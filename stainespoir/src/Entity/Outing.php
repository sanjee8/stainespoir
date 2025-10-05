<?php
namespace App\Entity;

use App\Repository\OutingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OutingRepository::class)]
#[ORM\Table(name: 'outing')]
class Outing
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(length:160)] private string $title = '';
    #[ORM\Column(type:'datetime_immutable')] private \DateTimeImmutable $startsAt;
    #[ORM\Column(length:160, nullable:true)] private ?string $location = null;
    #[ORM\Column(type:'text', nullable:true)] private ?string $description = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero(message: 'La limite doit être >= 0.')]
    private ?int $capacity = null; // null = illimité

    public function getCapacity(): ?int { return $this->capacity; }
    public function setCapacity(?int $capacity): self { $this->capacity = $capacity; return $this; }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;
        return $this;
    }

    public function getId():?int {return $this->id;}
    public function getTitle():string {return $this->title;}
    public function setTitle(string $t):self {$this->title=$t;return $this;}
    public function getStartsAt():\DateTimeImmutable {return $this->startsAt;}
    public function setStartsAt(\DateTimeImmutable $d):self {$this->startsAt=$d;return $this;}
    public function getLocation():?string {return $this->location;}
    public function setLocation(?string $l):self {$this->location=$l;return $this;}
    public function getDescription():?string {return $this->description;}
    public function setDescription(?string $d):self {$this->description=$d;return $this;}
    public function __toString(): string
    {
        return $this->getTitle() ?: ('Sortie #'.$this->getId());
    }
}
