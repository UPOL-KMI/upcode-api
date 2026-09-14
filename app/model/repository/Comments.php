<?php

namespace App\Model\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Order;
use App\Model\Entity\Comment;
use App\Model\Entity\CommentThread;
use App\Model\Entity\User;
use DateTime;

/**
 * @extends BaseRepository<Comment>
 */
class Comments extends BaseRepository
{
    private $threads;
    private $comments;

    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, Comment::class);
        $this->threads = $em->getRepository(CommentThread::class);
        $this->comments = $em->getRepository(Comment::class);
    }

    public function getThread(string $id): ?CommentThread
    {
        return $this->threads->find($id);
    }

    /**
     * Get the number of visible comments for given user.
     * The method requires the user entity, since it does not use ACL mechanism and compares
     * the authorship of comments directly as a performance optimization (we need only the count, not all entities).
     * @param CommentThread $thread
     * @param User $user
     * @param bool $allVisible True = all comments visible for the user, false = comments made by the user.
     * @return int
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    private function getCommentsCount(CommentThread $thread, User $user, bool $allVisible): int
    {
        $qb = $this->comments->createQueryBuilder('tc')
            ->select('COUNT(tc.id)')
            ->where('tc.commentThread = :id');

        if ($allVisible) {
            $qb->andWhere('tc.user = :user OR tc.isPrivate = 0');
        } else {
            $qb->andWhere('tc.user = :user');
        }

        $qb->setParameter('id', $thread->getId());
        $qb->setParameter('user', $user->getId());
        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get the number of all visible comments for given user.
     * @param CommentThread $thread
     * @param User $user
     * @return int
     */
    public function getThreadCommentsCount(CommentThread $thread, User $user): int
    {
        return $this->getCommentsCount($thread, $user, true);
    }

    /**
     * Get the number of comments made directly by given user.
     * @param CommentThread $thread
     * @param User $user
     * @return int
     */
    public function getAuthoredCommentsCount(CommentThread $thread, User $user): int
    {
        return $this->getCommentsCount($thread, $user, false);
    }

    /**
     * Get the number of visible comments for given user
     * The method requires the user entity, since it does not use ACL mechanism and compares the authorship of
     * comments directly as a performance optimization (we need only the last entity, not all entities).
     * @param CommentThread $thread
     * @param User $user
     * @return Comment|null
     */
    public function getThreadLastComment(CommentThread $thread, User $user): ?Comment
    {
        return $this->comments->matching(
            Criteria::create()
                ->where(
                    Criteria::expr()->andX(
                        Criteria::expr()->eq('commentThread', $thread),
                        Criteria::expr()->orX(
                            Criteria::expr()->eq('user', $user),
                            Criteria::expr()->eq('isPrivate', false)
                        )
                    )
                )
                ->orderBy(["postedAt" => Order::Descending])
                ->setMaxResults(1)
        )->first();
    }

    /**
     * Public comments posted in a thread at or after a given moment, oldest first.
     *
     * Written for the deferred comment notification (`CommentNotificationJobHandler`), which sends
     * one e-mail for a burst rather than one per comment and therefore has to ask, minutes later,
     * what actually arrived. Private comments are excluded here rather than by the caller because
     * a comment can be *made* private in the meantime -- the notification then correctly forgets
     * it, which the per-comment e-mail it replaces could not do.
     * @param CommentThread $thread
     * @param DateTime $since comments posted at or after this moment
     * @return Comment[]
     */
    public function findPublicSince(CommentThread $thread, DateTime $since): array
    {
        return $this->comments->createQueryBuilder("c")
            ->where("c.commentThread = :thread")
            ->andWhere("c.isPrivate = 0")
            ->andWhere("c.postedAt >= :since")
            ->orderBy("c.postedAt", "ASC")
            // `postedAt` is stored to the second, so a burst can tie -- measured on this
            // deployment, where two comments written in the same second came back in the
            // wrong order. The id breaks the tie so "the last one" is at least stable.
            ->addOrderBy("c.id", "ASC")
            ->setParameter("thread", $thread->getId())
            ->setParameter("since", $since)
            ->getQuery()->getResult();
    }
}
