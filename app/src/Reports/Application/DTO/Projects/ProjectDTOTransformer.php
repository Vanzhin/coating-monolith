<?php

declare(strict_types=1);

namespace App\Reports\Application\DTO\Projects;

use App\Reports\Domain\Aggregate\Project\Project;

class ProjectDTOTransformer
{
    public function fromEntity(Project $project): ProjectDTO
    {
        $dto = new ProjectDTO();
        $dto->id = $project->getId();
        $dto->title = $project->getTitle();
        $dto->description = $project->getDescription();
        $dto->counterpartyId = $project->getCounterparty()->getId();
        $dto->counterpartyTitle = $project->getCounterparty()->getTitle();

        return $dto;
    }

    /**
     * @param iterable<Project> $projects
     *
     * @return list<ProjectDTO>
     */
    public function fromEntityList(iterable $projects): array
    {
        $dtos = [];
        foreach ($projects as $project) {
            $dtos[] = $this->fromEntity($project);
        }

        return $dtos;
    }
}
