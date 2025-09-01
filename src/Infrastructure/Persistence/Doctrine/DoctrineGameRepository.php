<?php

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Ports\GameRepository;
use App\Domain\Game\Game;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Entity\GameRecord;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineGameRepository implements GameRepository
{
    public function __construct(
        private EntityManagerInterface $em,
        private GameSnapshotMapper     $mapper
    ) {
    }

    public function save(Game $game): void
    {
        $repo = $this->em->getRepository(GameRecord::class);
        $id = (string)$game->id();

        /** @var GameRecord|null $found */
        $found = $repo->find($id);

        if ($found) {
            $found->setState($this->mapper->toArray($game));
        } else {
            $found = new GameRecord($id, $this->mapper->toArray($game));
            $this->em->persist($found);
        }
        $this->em->flush();
    }

    public function get(GameId $id): ?Game
    {
        /** @var GameRecord|null $rec */
        $rec = $this->em->find(GameRecord::class, (string)$id);
        if (!$rec) {
            return null;
        }
        return $this->mapper->toDomain($rec->id(), $rec->state());
    }
}
