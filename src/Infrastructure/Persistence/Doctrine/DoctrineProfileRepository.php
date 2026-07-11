<?php

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Entity\ProfileRecord;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineProfileRepository implements ProfileRepository
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProfileSnapshotMapper $mapper,
    ) {
    }

    public function save(CaptainProfile $profile): void
    {
        $id = (string) $profile->id();

        /** @var ProfileRecord|null $found */
        $found = $this->em->find(ProfileRecord::class, $id);

        if ($found) {
            $found->setState($this->mapper->toArray($profile));
        } else {
            $found = new ProfileRecord($id, $this->mapper->toArray($profile));
            $this->em->persist($found);
        }
        $this->em->flush();
    }

    public function get(ProfileId $id): ?CaptainProfile
    {
        /** @var ProfileRecord|null $rec */
        $rec = $this->em->find(ProfileRecord::class, (string) $id);
        if (!$rec) {
            return null;
        }

        return $this->mapper->toDomain($rec->id(), $rec->state());
    }
}
