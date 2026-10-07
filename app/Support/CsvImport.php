<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Request;
use InvalidArgumentException;

final class CsvImport
{
    private const MAX_BYTES = 1048576;

    /**
     * @return list<array<string, string>>
     */
    public static function rowsFromRequest(Request $request): array
    {
        $input = $request->post()['csv_data'] ?? '';
        if (!is_string($input)) throw new InvalidArgumentException('CSV content must be text.');
        $csv = trim($input);
        if ($csv === '') {
            $csv = self::uploadedCsv($request);
        }

        return self::parse($csv);
    }

    private static function uploadedCsv(Request $request): string
    {
        $file = $request->files()['csv_file'] ?? null;
        if (is_array($file)) {
            foreach (['error', 'size', 'tmp_name'] as $key) {
                if (isset($file[$key]) && !is_int($file[$key]) && !is_string($file[$key])) {
                    throw new InvalidArgumentException('Upload one CSV file at a time.');
                }
            }
        }
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('CSV content or file is required.');
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('CSV upload failed.');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new InvalidArgumentException('CSV file must be 1 MB or smaller.');
        }

        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '' || !is_readable($path)) {
            throw new InvalidArgumentException('CSV upload could not be read.');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            throw new InvalidArgumentException('CSV content or file is required.');
        }

        return $contents;
    }

    /**
     * @return list<array<string, string>>
     */
    private static function parse(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        // Unreachable in practice: opening an in-memory php://temp stream does not fail under
        // normal PHP operation. Kept as a defensive guard against a hypothetical stream failure.
        if ($handle === false) {
            throw new InvalidArgumentException('CSV content could not be parsed.');
        }

        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        // Unreachable in practice: rowsFromRequest() only reaches parse() with a $csv that is
        // already guaranteed non-empty after trim(), so fgetcsv() always returns at least one
        // field for the header line. Kept as a defensive guard against a malformed stream.
        if ($header === false) {
            throw new InvalidArgumentException('CSV header is required.');
        }

        $columns = array_map(static fn (mixed $column): string => strtolower(trim((string) $column)), $header);
        if (in_array('', $columns, true)) {
            throw new InvalidArgumentException('CSV header contains an empty column.');
        }

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (self::isBlank($line)) {
                continue;
            }

            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = trim((string) ($line[$index] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        if ($rows === []) {
            throw new InvalidArgumentException('CSV must contain at least one data row.');
        }

        return $rows;
    }

    /** @param list<mixed> $row */
    private static function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
