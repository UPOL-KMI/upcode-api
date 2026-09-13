<?php

namespace App\Async\Handler;

use App\Model\Entity\AsyncJob;
use App\Model\Entity\User;
use App\Model\Repository\AsyncJobs;
use App\Model\Repository\AssignmentSolutions;
use App\Model\Repository\ShadowAssignmentPointsRepository;
use App\Async\IAsyncJobHandler;
use App\Async\Dispatcher;
use App\Helpers\Notifications\PointsChangedEmailsSender;
use DateTime;

/**
 * Deferred "your points have changed" notification, with one minute for the teacher to change
 * their mind.
 *
 * Upstream sends this e-mail inside the request that changes the points, so a teacher who mistypes
 * a number and corrects it a few seconds later writes to the student twice -- once with the wrong
 * figure. Reported on this deployment by the operator, who did exactly that.
 *
 * **The coalescing is "do not schedule a second one", not "cancel the first".** The job carries the
 * identity of what changed (a `ShadowAssignmentPoints` row, or an `AssignmentSolution`), not the
 * numbers, and reads the current state when it runs -- so one job that was scheduled a minute ago
 * already reports whatever the teacher settled on. Scheduling nothing when a job for the same thing
 * is still waiting therefore needs no cancellation and cannot race with the worker claiming it:
 * the worst that a lost race costs is the e-mail arriving from the older job, which says the same
 * thing.
 *
 * **An unfinished job counts as waiting even once `startedAt` is set.** The worker stamps that when
 * it *allocates* a job, which is not when it runs one: measured twice on this deployment, it
 * allocated a few seconds ahead of the scheduled time and executed exactly on it (06:12:29 /
 * 06:12:38, and 06:14:47 / 06:14:52). Those seconds are the only window in which the distinction
 * could matter, and coalescing is still the right answer inside it -- the allocated job has not
 * read the points yet either, and it reads them fresh when it runs. Refusing to coalesce there
 * would buy a vanishingly rare guarantee at the price of the second e-mail this exists to prevent.
 *
 * The delay is the whole point of the feature and is deliberately not configurable: a minute is
 * long enough to catch a correction and short enough that the student is not told stale news.
 */
class PointsNotificationJobHandler implements IAsyncJobHandler
{
    public const ID = 'pointsNotification';

    /** Points awarded for a shadow assignment -- the argument is the points row's id. */
    public const KIND_SHADOW = 'shadow';

    /** Points of a solution (bonus or overridden) -- the argument is the solution's id. */
    public const KIND_SOLUTION = 'solution';

    /** How long a change waits before it is written to the student. */
    public const DELAY_SECONDS = 60;

    /** @var bool */
    private $canceled = false;

    /** @var PointsChangedEmailsSender */
    private $pointsChangedEmailsSender;

    /** @var ShadowAssignmentPointsRepository */
    private $shadowAssignmentPoints;

    /** @var AssignmentSolutions */
    private $assignmentSolutions;

    public function __construct(
        PointsChangedEmailsSender $pointsChangedEmailsSender,
        ShadowAssignmentPointsRepository $shadowAssignmentPoints,
        AssignmentSolutions $assignmentSolutions
    ) {
        $this->pointsChangedEmailsSender = $pointsChangedEmailsSender;
        $this->shadowAssignmentPoints = $shadowAssignmentPoints;
        $this->assignmentSolutions = $assignmentSolutions;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function checkArgs(array $args): bool
    {
        return count($args) === 2
            && in_array($args[0], [self::KIND_SHADOW, self::KIND_SOLUTION], true)
            && is_string($args[1]);
    }

    public function execute(AsyncJob $job)
    {
        if ($this->canceled) {
            return;
        }

        $args = $job->getArguments();
        $kind = $args[0] ?? null;
        $id = $args[1] ?? null;
        if (!$id) {
            return;
        }

        // The entity may be gone by now -- points withdrawn, solution or group deleted. That is not
        // an error worth retrying, it is a notification that no longer has a subject.
        if ($kind === self::KIND_SHADOW) {
            $points = $this->shadowAssignmentPoints->get($id);
            if ($points !== null) {
                $this->pointsChangedEmailsSender->shadowPointsUpdated($points);
            }
        } elseif ($kind === self::KIND_SOLUTION) {
            $solution = $this->assignmentSolutions->get($id);
            if ($solution !== null) {
                $this->pointsChangedEmailsSender->solutionPointsUpdated($solution);
            }
        }
    }

    /**
     * Schedule the notification, unless one for the same thing is already waiting to be sent.
     * @param Dispatcher $dispatcher used to schedule the job
     * @param AsyncJobs $asyncJobs used to find a notification that is already waiting
     * @param User|null $user whoever changed the points
     * @param string $kind one of the KIND_* constants
     * @param string $id identifier of the points row or the solution
     * @return AsyncJob|null the job that was scheduled, or null when an earlier one will cover it
     */
    public static function scheduleAsyncJob(
        Dispatcher $dispatcher,
        AsyncJobs $asyncJobs,
        ?User $user,
        string $kind,
        string $id
    ): ?AsyncJob {
        foreach ($asyncJobs->findPendingJobs(self::ID) as $pending) {
            $args = $pending->getArguments();
            if (($args[0] ?? null) === $kind && ($args[1] ?? null) === $id) {
                return null;
            }
        }

        $job = new AsyncJob($user, self::ID, [$kind, $id]);
        $scheduledAt = new DateTime();
        $scheduledAt->modify("+" . self::DELAY_SECONDS . " seconds");
        $dispatcher->schedule($job, $scheduledAt);
        return $job;
    }

    public function cancel(): void
    {
        $this->canceled = true;
    }
}
