<?php


namespace ChatStats;


class DateHelper
{
    protected static $map = [
        'Понедельник',
        'Вторник',
        'Среда',
        'Четверг',
        'Пятница',
        'Суббота',
        'Воскресение'
    ];

    protected static $months = [
        '01' => 'Январь',
        '02' => 'Февраль',
        '03' => 'Март',
        '04' => 'Апрель',
        '05' => 'Май',
        '06' => 'Июнь',
        '07' => 'Июль',
        '08' => 'Август',
        '09' => 'Сентябрь',
        '10' => 'Октябрь',
        '11' => 'Ноябрь',
        '12' => 'Декабрь',
    ];

    public static function getDayName(int $weekDayNum): string
    {
        return self::$map[$weekDayNum - 1];
    }

    public static function getMonthName(string $monthNum): string
    {
        return self::$months[$monthNum] ?? $monthNum;
    }
}