<?php
declare(strict_types=1);
namespace App\Shared\Domain\Audit;

final readonly class FieldChange implements \JsonSerializable
{
    private function __construct(public ChangeOp $op, public string $path, public mixed $old, public mixed $new) {}

    public static function set(string $path, mixed $old, mixed $new): self { return new self(ChangeOp::Set, $path, $old, $new); }
    public static function add(string $path, mixed $new): self { return new self(ChangeOp::Add, $path, null, $new); }
    public static function remove(string $path, mixed $old): self { return new self(ChangeOp::Remove, $path, $old, null); }

    public function jsonSerialize(): array
    {
        return match ($this->op) {
            ChangeOp::Set => ['op' => 'set', 'path' => $this->path, 'old' => $this->old, 'new' => $this->new],
            ChangeOp::Add => ['op' => 'add', 'path' => $this->path, 'new' => $this->new],
            ChangeOp::Remove => ['op' => 'remove', 'path' => $this->path, 'old' => $this->old],
        };
    }

    public static function fromArray(array $row): self
    {
        return new self(ChangeOp::from((string) $row['op']), (string) $row['path'], $row['old'] ?? null, $row['new'] ?? null);
    }
}
