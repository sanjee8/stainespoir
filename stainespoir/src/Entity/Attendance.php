<?php
namespace App\Entity;

use App\Repository\AttendanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AttendanceRepository::class)]
#[ORM\Table(name: 'attendance')]
#[ORM\UniqueConstraint(columns: ['child_id', 'date'])]
class Attendance
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false, onDelete:'CASCADE')]
    private ?Child $child = null;

    #[ORM\Column(type:'date')] private \DateTimeInterface $date;

    // present | absent | late | excused
    #[ORM\Column(length:16)] private string $status = 'present';

    #[ORM\Column(type:'text', nullable:true)] private ?string $notes = null;

    public function getId(): ?int { return $this->id; }
    public function getChild(): ?Child { return $this->child; }
    public function setChild(Child $c): self { $this->child = $c; return $this; }

    public function getDate(): \DateTimeInterface { return $this->date; }
    public function setDate(\DateTimeInterface $d): self { $this->date = $d; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }
}
