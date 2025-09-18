<?php
namespace App\Entity;

use App\Enum\InsuranceStatus;
use App\Enum\InsuranceType;
use App\Repository\InsuranceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InsuranceRepository::class)]
#[ORM\Table(name: 'insurance')]
#[ORM\UniqueConstraint(name: 'uniq_child_type_year', columns: ['child_id','type','school_year'])]
class Insurance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type:'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Child::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Child $child = null;

    #[ORM\Column(type: 'string', length: 16, enumType: InsuranceType::class)]
    private InsuranceType $type;

    // Format "2025-2026"
    #[ORM\Column(name: 'school_year', type: 'string', length: 9)]
    private string $schoolYear;

    // Chemin web relatif depuis /public (ex: /uploads/assurances/12/2025-2026/RC-abc.pdf)
    #[ORM\Column(type:'string', length:255)]
    private string $path;

    #[ORM\Column(type:'string', length:255)]
    private string $originalName;

    #[ORM\Column(type:'string', length:100)]
    private string $mimeType;

    #[ORM\Column(type:'integer')]
    private int $size;

    #[ORM\Column(type:'datetime_immutable')]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\Column(type: 'string', length: 16, enumType: InsuranceStatus::class)]
    private InsuranceStatus $status = InsuranceStatus::PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $validatedBy = null;

    #[ORM\Column(type:'datetime_immutable', nullable:true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\Column(type:'text', nullable:true)]
    private ?string $adminComment = null;

    // getters/setters standard...
    public function getId(): ?int { return $this->id; }
    public function getChild(): ?Child { return $this->child; }
    public function setChild(?Child $child): self { $this->child = $child; return $this; }
    public function getType(): InsuranceType { return $this->type; }
    public function setType(InsuranceType $type): self { $this->type = $type; return $this; }
    public function getSchoolYear(): string { return $this->schoolYear; }
    public function setSchoolYear(string $schoolYear): self { $this->schoolYear = $schoolYear; return $this; }
    public function getPath(): string { return $this->path; }
    public function setPath(string $path): self { $this->path = $path; return $this; }
    public function getOriginalName(): string { return $this->originalName; }
    public function setOriginalName(string $originalName): self { $this->originalName = $originalName; return $this; }
    public function getMimeType(): string { return $this->mimeType; }
    public function setMimeType(string $mimeType): self { $this->mimeType = $mimeType; return $this; }
    public function getSize(): int { return $this->size; }
    public function setSize(int $size): self { $this->size = $size; return $this; }
    public function getUploadedAt(): \DateTimeImmutable { return $this->uploadedAt; }
    public function setUploadedAt(\DateTimeImmutable $uploadedAt): self { $this->uploadedAt = $uploadedAt; return $this; }
    public function getStatus(): InsuranceStatus { return $this->status; }
    public function setStatus(InsuranceStatus $status): self { $this->status = $status; return $this; }
    public function getValidatedBy(): ?User { return $this->validatedBy; }
    public function setValidatedBy(?User $validatedBy): self { $this->validatedBy = $validatedBy; return $this; }
    public function getValidatedAt(): ?\DateTimeImmutable { return $this->validatedAt; }
    public function setValidatedAt(?\DateTimeImmutable $validatedAt): self { $this->validatedAt = $validatedAt; return $this; }
    public function getAdminComment(): ?string { return $this->adminComment; }
    public function setAdminComment(?string $adminComment): self { $this->adminComment = $adminComment; return $this; }
}
