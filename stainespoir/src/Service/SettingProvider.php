<?php

namespace App\Service;

use App\Repository\SettingRepository;
use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

class SettingProvider
{
    public function __construct(
        private readonly SettingRepository $repo,
        private readonly EntityManagerInterface $em
    ) {}

    public function get(string $name, mixed $default = null): ?string
    {
        $s = $this->repo->findOneBy(['name' => $name]);
        return $s?->getValue() ?? $default;
    }

    /** @return array<string,string> */
    public function group(string $prefix): array
    {
        $out = [];
        foreach ($this->repo->findByPrefix($prefix) as $s) {
            $out[$s->getName()] = $s->getValue();
        }
        return $out;
    }

    /** pratique pour seeds/console */
    public function set(string $name, string $value): void
    {
        $s = $this->repo->findOneBy(['name' => $name]) ?? (new Setting())->setName($name);
        $s->setValue($value);
        $this->em->persist($s);
        $this->em->flush();
    }
}
