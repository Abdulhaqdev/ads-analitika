<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DateRange
{
    public const PRESETS = ['today' => 'Bugun', 'yesterday' => 'Kecha', '3' => '3 kun', '7' => '7 kun', '14' => '14 kun', '30' => '30 kun'];

    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $key,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $key = (string) $request->query('r', '7');

        if ($key === 'custom') {
            try {
                $from = Carbon::parse((string) $request->query('from'))->startOfDay();
                $to = Carbon::parse((string) $request->query('to'))->startOfDay();
            } catch (\Throwable) {
                return self::preset('7');
            }

            $to = $to->gt(today()) ? today() : $to;
            $from = $from->gt($to) ? $to->copy() : $from;
            $from = $from->lt($to->copy()->subDays(89)) ? $to->copy()->subDays(89) : $from;

            return new self($from, $to, 'custom');
        }

        return self::preset(array_key_exists($key, self::PRESETS) ? $key : '7');
    }

    public static function preset(string $key): self
    {
        return match ($key) {
            'today' => new self(today(), today(), 'today'),
            'yesterday' => new self(today()->subDay(), today()->subDay(), 'yesterday'),
            default => new self(today()->subDays((int) $key), today()->subDay(), $key),
        };
    }

    public static function lastDays(int $days): self
    {
        return self::preset((string) $days);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function prevTo(): Carbon
    {
        return $this->from->copy()->subDay();
    }

    public function prevFrom(): Carbon
    {
        return $this->prevTo()->subDays($this->days() - 1);
    }

    /** Account-level windows (3/7/14/30 days ending yesterday) are only valid for these ranges. */
    public function windowDays(): ?int
    {
        return $this->to->isSameDay(today()->subDay()) && in_array($this->days(), [3, 7, 14, 30], true) ? $this->days() : null;
    }

    public function label(): string
    {
        return $this->from->isSameDay($this->to)
            ? $this->from->format('d.m.Y')
            : $this->from->format('d.m').' – '.$this->to->format('d.m.Y');
    }

    /** @return array<string, string> */
    public function query(): array
    {
        return $this->key === 'custom'
            ? ['r' => 'custom', 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString()]
            : ['r' => $this->key];
    }
}
