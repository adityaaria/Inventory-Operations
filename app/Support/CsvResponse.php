<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Response;

final class CsvResponse
{
    public static function download(string $filename, string $body): Response
    {
        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
