// Геометрия и формулы ПТМ — ЕДИНСТВЕННОЕ зеркало доменных SectionFactor\* на бэке (источник истины).
// Дублируем ради офлайна (калькулятор /tools/section-factor считает в браузере). Чтобы фронт не разъехался
// с доменом, модуль прогоняется тестом паритета (tests/Unit/.../SectionFactorParityTest.php): та же матрица
// идёт через PHP-домен и через этот файл, расхождение — красный на CI. Правишь формулу в домене — обязан
// подтянуть сюда, иначе тест упадёт.
//
// ПТМ = площадь сечения / обогреваемый периметр (НПБ 236-97), периметр — развёрнутый контур.
// Чистый ES-модуль без Stimulus/DOM — лежит в assets/ (НЕ в controllers/, иначе зарегистрируется контроллером).

export const STEEL_DENSITY = 7.85;
const rad2deg = (r) => (r * 180) / Math.PI;
const deg2rad = (d) => (d * Math.PI) / 180;

// Геометрия по типу профиля: dims (объект размеров) → площадь и периметр по схеме обогрева.
export const GEO = {
    i_beam: {
        area: (d) => (d.height - 2 * d.flangeThickness) * d.webThickness
            + 2 * d.flangeWidth * d.flangeThickness
            + 4 * (d.filletRadius || 0) ** 2 * (1 - Math.PI / 4),
        perimeter: (d, s) => {
            // боковая сторона минус 2 скругления стенка–полка (зеркало IBeamSection, u=0)
            const side = d.height + d.flangeWidth - d.webThickness + 2 * (d.filletRadius || 0) * (Math.PI / 2 - 2);
            return (s.top ? d.flangeWidth : 0) + (s.bottom ? d.flangeWidth : 0) + (s.left ? side : 0) + (s.right ? side : 0);
        },
    },
    channel: {
        area: (d) => {
            const a = Math.atan(d.slope || 0);
            const corr = (x) => x * x / Math.tan(deg2rad((90 + rad2deg(a)) / 2)) - x * x * Math.PI * (90 - rad2deg(a)) / 360;
            return d.height * d.webThickness + 2 * (d.flangeWidth - d.webThickness) * d.flangeThickness
                + 2 * corr(d.innerRadius || 0) - 2 * corr(d.outerRadius || 0);
        },
        perimeter: (d, s) => {
            const a = Math.atan(d.slope || 0);
            const halfDeg = 90 - (90 + rad2deg(a)) / 2;
            const corr = (x) => 2 * x * Math.PI * 2 * halfDeg / 360 - 2 * x * Math.tan(deg2rad(halfDeg));
            let p = (s.top ? d.flangeWidth : 0) + (s.bottom ? d.flangeWidth : 0) + (s.left ? d.height : 0);
            if (s.right) {
                p += d.height + 2 * (d.flangeWidth - d.webThickness) * (1 / Math.cos(a) - (d.slope || 0))
                    + 2 * corr(d.innerRadius || 0) + 2 * corr(d.outerRadius || 0);
            }
            return p;
        },
    },
    angle: {
        area: (d) => (d.legA + d.legB - d.thickness) * d.thickness
            + (d.outerRadius || 0) ** 2 * (1 - Math.PI / 4) - 2 * (d.innerRadius || 0) ** 2 * (1 - Math.PI / 4),
        // Три независимые грани: наружная полка b₁ (left), b₂ (top), внутренний контур (right, несёт
        // скругления R/r). Все три = полный контур 2·(b₁+b₂). Зеркало AngleSection::heatedPerimeter.
        perimeter: (d, s) => {
            const c = Math.PI / 2 - 2;
            const inner = (d.legA + d.legB) + (d.outerRadius || 0) * c + 2 * (d.innerRadius || 0) * c;
            return (s.left ? d.legA : 0) + (s.top ? d.legB : 0) + (s.right ? inner : 0);
        },
    },
    pipe: {
        area: (d) => Math.PI / 4 * (d.outerDiameter ** 2 - (d.outerDiameter - 2 * d.wallThickness) ** 2),
        perimeter: (d) => Math.PI * d.outerDiameter,
    },
    round_bar: {
        area: (d) => Math.PI * d.diameter ** 2 / 4,
        perimeter: (d) => Math.PI * d.diameter,
    },
    rect_hollow: {
        area: (d) => {
            const base = 2 * d.wallThickness * (d.height + d.width - 2 * d.wallThickness);
            if (!d.outerRadius) return base;
            const ri = Math.max(d.outerRadius - d.wallThickness, 0);
            return base - (4 - Math.PI) * (d.outerRadius ** 2 - ri ** 2);
        },
        perimeter: (d, s) => (s.top ? d.width : 0) + (s.bottom ? d.width : 0) + (s.left ? d.height : 0) + (s.right ? d.height : 0),
    },
    sheet: {
        area: (d) => d.thickness * 1000,
        perimeter: (d, s) => ((s.top ? 1 : 0) + (s.bottom ? 1 : 0)) * 1000,
    },
};

// Полный расчёт по типу/размерам/схеме: площадь, периметр и производные. null — если геометрия вырождена
// (нет обогреваемых граней / нулевой размер). Зеркало SectionFactorCalculator.
export function compute(type, dims, scheme) {
    const geo = GEO[type];
    if (!geo || !dims) return null;
    const area = geo.area(dims);
    const perimeter = geo.perimeter(dims, scheme || {});
    if (!(area > 0) || !(perimeter > 0)) return null;
    const massPerMeter = area / 1000 * STEEL_DENSITY;

    return {
        area,
        perimeter,
        ptm: area / perimeter,
        spm: perimeter / 1000,
        mass: massPerMeter,
        spt: 1000 / massPerMeter * (perimeter / 1000),
    };
}
