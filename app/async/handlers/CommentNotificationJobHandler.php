<?php

namespace App\Async\Handler;

use App\Model\Entity\AsyncJob;
use App\Model\Entity\Comment;
use App\Model\Entity\User;
use App\Model\Repository\AsyncJobs;
use App\Model\Repository\Assignments;
use App\Model\Repository\AssignmentSolutions;
use App\Model\Repository\Comments;
use App\Model\Repository\ReferenceExerciseSolutions;
use App\Async\IAsyncJobHandler;
use App\Async\Dispatcher;
use App\Helpers\Notifications\AssignmentCommentsEmailsSender;
use App\Helpers\Notifications\SolutionCommentsEmailsSender;
use DateTime;

/**
 * Deferred "there is a new comment" notification: one e-mail per discussion per five minutes,
 * instead of one per comment.
 *
 * Upstream writes to every member of the group inside the request that posts the comment, so a
 * seminar where ten people ask something in the same minute sends ten e-mails to everybody else.
 * Asked for by the operator, who had already had the same round with the points notification and
 * recognised the shape.
 *
 * **What the e-mail says is the newest comment**, with a line naming how many others arrived with
 * it -- the operator's instruction ("send only the last one"), kept honest. A comment is not a
 * state that can be re-read like a points total is: nine of the ten texts genuinely are not in
 * that e-mail, and the reader has to be told so rather than left believing they saw the burst.
 *
 * **Coalescing is "do not schedule a second one", as with the points job.** The arguments are the
 * thread's identity and the moment the first comment of the burst arrived, never a comment's id,
 * so the job that is already waiting covers everything posted after it and needs no cancellation
 * and no rescheduling. `AsyncJob` has no update path that a worker could race with.
 *
 * A comment made private inside the window disappears from the notification: the query at send
 * time asks for public comments only. That is a small improvement over the per-comment e-mail,
 * which had already left by then.
 *
 * Five minutes, deliberately not configurable: long enough to gather a seminar's burst, short
 * enough that an answer to a question still arrives while the question is warm.
 */
class CommentNotificationJobHandler implements IAsyncJobHandler
{
    public const ID = 'commentNotification';

    /** Discussion under an assignment -- the argument is the assignment's id (= the thread's). */
    public const KIND_ASSIGNMENT = 'assignment';

    /** Discussion under a student's solution. */
    public const KIND_SOLUTION = 'solution';

    /** Discussion under a reference solution. */
    public const KIND_REFERENCE_SOLUTION = 'referenceSolution';

    /** How long a discussion is allowed to gather before anybody is written to. */
    public const DELAY_SECONDS = 300;

    /** @var bool */
    private $canceled = false;

    /** @var AssignmentCommentsEmailsSender */
    private $assignmentCommentsEmailsSender;

    /** @var SolutionCommentsEmailsSender */
    private $solutionCommentsEmailsSender;

    /** @var Comments */
    private $comments;

    /** @var Assignments */
    private $assignments;

    /** @var AssignmentSolutions */
    private $assignmentSolutions;

    /** @var ReferenceExerciseSolutions */
    private $referenceExerciseSolutions;

    public function __construct(
        AssignmentCommentsEmailsSender $assignmentCommentsEmailsSender,
        SolutionCommentsEmailsSender $solutionCommentsEmailsSender,
        Comments $comments,
        Assignments $assignments,
        AssignmentSolutions $assignmentSolutions,
        ReferenceExerciseSolutions $referenceExerciseSolutions
    ) {
        $this->assignmentCommentsEmailsSender = $assignmentCommentsEmailsSender;
        $this->solutionCommentsEmailsSender = $solutionCommentsEmailsSender;
        $this->comments = $comments;
        $this->assignments = $assignments;
        $this->assignmentSolutions = $assignmentSolutions;
        $this->referenceExerciseSolutions = $referenceExerciseSolutions;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function checkArgs(array $args): bool
    {
        return count($args) === 3
            && in_array($args[0], [self::KIND_ASSIGNMENT, self::KIND_SOLUTION, self::KIND_REFERENCE_SOLUTION], true)
            && is_string($args[1])
            && is_numeric($args[2]);
    }

    public function execute(AsyncJob $job)
    {
        if ($this->canceled) {
            return;
        }

        $args = $job->getArguments();
        $kind = $args[0] ?? null;
        $id = $args[1] ?? null;
        $since = $args[2] ?? null;
        if (!$id || $since === null) {
            return;
        }

        $thread = $this->comments->getThread($id);
        if ($thread === null) {
            return;  // the discussion is gone; so is the reason to write about it
        }

        $comments = $this->comments->findPublicSince($thread, (new DateTime())->setTimestamp((int)$since));
        if (count($comments) === 0) {
            return;  // every comment of the burst was withdrawn or made private
        }

        /** @var Comment $latest */
        $latest = $comments[count($comments) - 1];
        $others = count($comments) - 1;

        if ($kind === self::KIND_ASSIGNMENT) {
            $assignment = $this->assignments->get($id);
            if ($assignment !== null) {
                $this->assignmentCommentsEmailsSender->assignmentComment($assignment, $latest, $others);
            }
        } elseif ($kind === self::KIND_SOLUTION) {
            $solution = $this->assignmentSolutions->get($id);
            if ($solution !== null) {
                $this->solutionCommentsEmailsSender->assignmentSolutionComment($solution, $latest, $others);
            }
        } elseif ($kind === self::KIND_REFERENCE_SOLUTION) {
            $solution = $this->referenceExerciseSolutions->get($id);
            if ($solution !== null) {
                $this->solutionCommentsEmailsSender->referenceSolutionComment($solution, $latest, $others);
            }
        }
    }

    /**
     * Schedule the notification, unless one for the same discussion is already waiting.
     * @param Dispatcher $dispatcher used to schedule the job
     * @param AsyncJobs $asyncJobs used to find a notification that is already waiting
     * @param User|null $user whoever wrote the comment
     * @param string $kind one of the KIND_* constants
     * @param string $id identifier of the thread (which is the assignment's or solution's own)
     * @param DateTime $since the moment this comment was posted
     * @return AsyncJob|null the job that was scheduled, or null when an earlier one will cover it
     */
    public static function scheduleAsyncJob(
        Dispatcher $dispatcher,
        AsyncJobs $asyncJobs,
        ?User $user,
        string $kind,
        string $id,
        DateTime $since
    ): ?AsyncJob {
        foreach ($asyncJobs->findPendingJobs(self::ID) as $pending) {
            $args = $pending->getArguments();
            if (($args[0] ?? null) === $kind && ($args[1] ?? null) === $id) {
                return null;
            }
        }

        $job = new AsyncJob($user, self::ID, [$kind, $id, (string)$since->getTimestamp()]);
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
