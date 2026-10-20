// Харнесс теста паритета PHP↔JS: читает матрицу кейсов [{type, dims, scheme}, …] из файла (argv[2]),
// прогоняет через то же чистое зеркало формул, что и фронт, и печатает результаты JSON-массивом.
// Вызывается из SectionFactorParityTest. Запуск: node section_factor_parity_harness.mjs <input.json>
import { readFileSync } from 'node:fs';
import { compute } from '../../assets/section_factor_geometry.mjs';

const cases = JSON.parse(readFileSync(process.argv[2], 'utf8'));
process.stdout.write(JSON.stringify(cases.map((c) => compute(c.type, c.dims, c.scheme))));
