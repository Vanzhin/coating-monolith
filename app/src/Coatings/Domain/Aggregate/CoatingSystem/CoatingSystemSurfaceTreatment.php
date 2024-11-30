<?php

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

/**
 * Типа покрытия по назначению
 */
enum CoatingSystemSurfaceTreatment: string
{
    /*
     * Sa3.
     */
    case Sa3 = 'абразивоструйная очистка до степени Sa3(ISO 8501-1)';

    /*
     * Sa2,5.
     */
    case Sa25 = 'абразивоструйная очистка до степени Sa2,5(ISO 8501-1)';

    /*
     * Sa2.
     */
    case Sa2 = 'абразивоструйная очистка до степени Sa2(ISO 8501-1)';

    /*
     * St2.
     */
    case St2 = 'механическая очистка до степени St2(ISO 8501-1)';

    /*
     * St3.
     */
    case St3 = 'механическая очистка до степени St3(ISO 8501-1)';

    /*
     * Гидроструйная очистка.
     */
    case HYDRO = 'Гидроструйная очистка';
}
