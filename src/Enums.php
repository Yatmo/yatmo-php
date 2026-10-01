<?php

declare(strict_types=1);

namespace Yatmo;

/** Travel modes of the routing endpoints and of the travel times in the summary. */
enum TravelMode: string
{
    case Walking = 'Walking';
    case Bicycling = 'Bicycling';
    case Driving = 'Driving';
    case Transit = 'Transit';

    /** Code used inside the summary payload (`td[].tm`). */
    public function summaryCode(): int
    {
        return match ($this) {
            self::Driving => 1,
            self::Walking => 2,
            self::Bicycling => 3,
            self::Transit => 4,
        };
    }

    public static function fromSummaryCode(int $code): ?self
    {
        return match ($code) {
            1 => self::Driving,
            2 => self::Walking,
            3 => self::Bicycling,
            4 => self::Transit,
            default => null,
        };
    }
}

/** The seven map styles of the web plugins and of the static map endpoint. */
enum MapStyle: int
{
    case Liberty = 1;
    case Basic = 2;
    case Bright = 3;
    case ThreeD = 4;
    case Positron = 5;
    case Dark = 6;
    case LibertyStonehedge = 7;
}
