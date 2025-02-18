<?php

namespace App\Enums;

enum ActiveMonthsEnum: string
{
    case JANUARY = '01';
    case FEBRUARY = '02';
    case MARCH = '03';
    case APRIL = '04';
    case MAY = '05';
    case JUNE = '06';
    case JULY = '07';
    case AUGUST = '08';
    case SEPTEMBER = '09';
    case OCTOBER = '10';
    case NOVEMBER = '11';
    case DECEMBER = '12';

    public function getLabel(): string
    {
        return $this->value;
    }
}
