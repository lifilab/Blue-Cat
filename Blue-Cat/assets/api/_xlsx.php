<?php
declare(strict_types=1);

/**
 * Utilidades OOXML deliberadamente pequenas para importar/exportar una hoja.
 * No evalua formulas, macros ni vinculos externos.
 */

const BLUECAT_XLSX_MAX_ARCHIVE_BYTES = 10 * 1024 * 1024;
const BLUECAT_XLSX_MAX_ENTRIES = 256;
const BLUECAT_XLSX_MAX_UNCOMPRESSED_BYTES = 32 * 1024 * 1024;
const BLUECAT_XLSX_MAX_ENTRY_BYTES = 32 * 1024 * 1024;
const BLUECAT_XLSX_MAX_COMPRESSION_RATIO = 250.0;
const BLUECAT_XLSX_MAX_ROWS = 5001; // cabecera + hasta 5.000 datos
const BLUECAT_XLSX_MAX_COLUMNS = 256;
const BLUECAT_XLSX_MAX_CELLS = 100000;
const BLUECAT_XLSX_MAX_STRING_CHARACTERS = 32767;

function bluecatXlsxRequireRuntime(): void {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El soporte XLSX requiere la extension ZIP de PHP');
    }
    if (!class_exists('XMLReader')) {
        throw new RuntimeException('El soporte XLSX requiere la extension XMLReader de PHP');
    }
}

function bluecatXlsxSafeEntryName(string $name): bool {
    if ($name === '' || strlen($name) > 255 || str_contains($name, "\0") || str_contains($name, '\\')) return false;
    if ($name[0] === '/' || preg_match('/^[A-Za-z]:/', $name)) return false;
    foreach (explode('/', rtrim($name, '/')) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') return false;
    }
    return true;
}

/** @return array<string,array{name:string,size:int,comp_size:int}> */
function bluecatXlsxInspectArchive(ZipArchive $zip): array {
    if ($zip->numFiles <= 0 || $zip->numFiles > BLUECAT_XLSX_MAX_ENTRIES) {
        throw new InvalidArgumentException('El XLSX contiene una cantidad de archivos internos no permitida');
    }

    $entries = [];
    $seenNames = [];
    $totalSize = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        if (!is_array($stat)) throw new InvalidArgumentException('No se pudo inspeccionar el contenido del XLSX');
        $name = (string)($stat['name'] ?? '');
        if (!bluecatXlsxSafeEntryName($name)) throw new InvalidArgumentException('El XLSX contiene una ruta interna no segura');
        if (isset($seenNames[$name])) throw new InvalidArgumentException('El XLSX contiene nombres internos duplicados');
        $seenNames[$name] = true;
        if (str_ends_with($name, '/')) continue;

        $size = (int)($stat['size'] ?? -1);
        $compressed = (int)($stat['comp_size'] ?? -1);
        if ($size < 0 || $compressed < 0 || $size > BLUECAT_XLSX_MAX_ENTRY_BYTES) {
            throw new InvalidArgumentException('El XLSX contiene un archivo interno demasiado grande');
        }
        $totalSize += $size;
        if ($totalSize > BLUECAT_XLSX_MAX_UNCOMPRESSED_BYTES) {
            throw new InvalidArgumentException('El contenido descomprimido del XLSX supera el limite permitido');
        }
        if ($size > 0 && $compressed === 0) {
            throw new InvalidArgumentException('El XLSX contiene una entrada comprimida invalida');
        }
        if ($compressed > 0 && ($size / $compressed) > BLUECAT_XLSX_MAX_COMPRESSION_RATIO) {
            throw new InvalidArgumentException('El XLSX excede la relacion de compresion permitida');
        }
        $entries[$name] = ['name'=>$name, 'size'=>$size, 'comp_size'=>$compressed];
    }
    return $entries;
}

/** @param array<string,array{name:string,size:int,comp_size:int}> $entries */
function bluecatXlsxReadEntry(ZipArchive $zip, array $entries, string $name, bool $required = true): ?string {
    if (!isset($entries[$name])) {
        if ($required) throw new InvalidArgumentException("El XLSX no contiene {$name}");
        return null;
    }
    $contents = $zip->getFromName($name);
    if (!is_string($contents) || strlen($contents) !== $entries[$name]['size']) {
        throw new InvalidArgumentException("No se pudo leer {$name} del XLSX");
    }
    return $contents;
}

function bluecatXlsxXmlReader(string $xml, string $label): XMLReader {
    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        throw new InvalidArgumentException("{$label} contiene declaraciones XML no permitidas");
    }
    $reader = new XMLReader();
    $flags = LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING;
    if (!$reader->XML($xml, null, $flags)) throw new InvalidArgumentException("{$label} no es XML valido");
    $reader->setParserProperty(XMLReader::LOADDTD, false);
    $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
    $reader->setParserProperty(XMLReader::VALIDATE, false);
    return $reader;
}

function bluecatXlsxRelationshipId(string $workbookXml): string {
    $reader = bluecatXlsxXmlReader($workbookXml, 'xl/workbook.xml');
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'sheet') continue;
            $relationshipId = $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')
                ?: $reader->getAttributeNs('id', 'http://purl.oclc.org/ooxml/officeDocument/relationships')
                ?: $reader->getAttribute('r:id');
            if (is_string($relationshipId) && $relationshipId !== '') return $relationshipId;
            break;
        }
    } finally {
        $reader->close();
    }
    throw new InvalidArgumentException('El XLSX no declara una primera hoja valida');
}

function bluecatXlsxResolveTarget(string $baseDirectory, string $target): string {
    $target = str_replace('\\', '/', trim($target));
    if ($target === '' || str_contains($target, "\0") || preg_match('~^[A-Za-z][A-Za-z0-9+.-]*:~', $target)) {
        throw new InvalidArgumentException('El XLSX contiene un destino de hoja no seguro');
    }
    $candidate = str_starts_with($target, '/') ? ltrim($target, '/') : trim($baseDirectory, '/') . '/' . $target;
    $segments = [];
    foreach (explode('/', $candidate) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') {
            if (!$segments) throw new InvalidArgumentException('El XLSX intenta salir de su raiz interna');
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    $resolved = implode('/', $segments);
    if (!str_starts_with($resolved, 'xl/worksheets/') || !str_ends_with(strtolower($resolved), '.xml') || !bluecatXlsxSafeEntryName($resolved)) {
        throw new InvalidArgumentException('La primera hoja del XLSX apunta a una ubicacion no permitida');
    }
    return $resolved;
}

function bluecatXlsxWorksheetPath(string $relationshipsXml, string $relationshipId): string {
    $reader = bluecatXlsxXmlReader($relationshipsXml, 'xl/_rels/workbook.xml.rels');
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Relationship') continue;
            if ((string)$reader->getAttribute('Id') !== $relationshipId) continue;
            if (strcasecmp((string)$reader->getAttribute('TargetMode'), 'External') === 0) {
                throw new InvalidArgumentException('El XLSX intenta cargar una hoja externa');
            }
            $type = (string)$reader->getAttribute('Type');
            if (!str_ends_with($type, '/worksheet')) throw new InvalidArgumentException('La primera relacion no corresponde a una hoja');
            return bluecatXlsxResolveTarget('xl', (string)$reader->getAttribute('Target'));
        }
    } finally {
        $reader->close();
    }
    throw new InvalidArgumentException('No se encontro la primera hoja del XLSX');
}

/** @return list<string> */
function bluecatXlsxSharedStrings(?string $sharedStringsXml): array {
    if ($sharedStringsXml === null) return [];
    $reader = bluecatXlsxXmlReader($sharedStringsXml, 'xl/sharedStrings.xml');
    $strings = [];
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') continue;
            $depth = $reader->depth;
            $value = '';
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'si') break;
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') $value .= $reader->readString();
            }
            if (mb_strlen($value, 'UTF-8') > BLUECAT_XLSX_MAX_STRING_CHARACTERS) throw new InvalidArgumentException('El XLSX contiene una cadena demasiado larga');
            $strings[] = $value;
            if (count($strings) > BLUECAT_XLSX_MAX_CELLS) throw new InvalidArgumentException('El XLSX contiene demasiadas cadenas compartidas');
        }
    } finally {
        $reader->close();
    }
    return $strings;
}

function bluecatXlsxColumnIndex(string $reference): ?int {
    if (!preg_match('/^([A-Za-z]{1,4})[1-9][0-9]*$/', $reference, $matches)) return null;
    $index = 0;
    foreach (str_split(strtoupper($matches[1])) as $letter) $index = ($index * 26) + (ord($letter) - 64);
    return $index - 1;
}

/** @param list<string> $sharedStrings */
function bluecatXlsxCellValue(XMLReader $reader, array $sharedStrings): string {
    $depth = $reader->depth;
    $type = (string)$reader->getAttribute('t');
    $value = '';
    $inline = '';
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'c') break;
        if ($reader->nodeType !== XMLReader::ELEMENT) continue;
        if ($reader->localName === 'f') throw new InvalidArgumentException('El XLSX contiene una fórmula; convierta la celda a un valor antes de importar');
        if ($reader->localName === 'v') $value = $reader->readString();
        elseif ($reader->localName === 't' && $type === 'inlineStr') $inline .= $reader->readString();
    }
    if ($type === 's') {
        if (!ctype_digit($value) || !array_key_exists((int)$value, $sharedStrings)) {
            throw new InvalidArgumentException('El XLSX referencia una cadena compartida inexistente');
        }
        return $sharedStrings[(int)$value];
    }
    if ($type === 'inlineStr') return $inline;
    if ($type === 'b') return $value === '1' ? '1' : '0';
    if ($type === 'e') return '';
    return $value;
}

/**
 * Lee la primera hoja y devuelve sus filas, incluida la cabecera.
 *
 * @return list<list<string>>
 */
function bluecatXlsxReadRows(string $path, int $maxRows = BLUECAT_XLSX_MAX_ROWS, int $maxColumns = 64): array {
    bluecatXlsxRequireRuntime();
    if ($maxRows < 1 || $maxRows > BLUECAT_XLSX_MAX_ROWS || $maxColumns < 1 || $maxColumns > BLUECAT_XLSX_MAX_COLUMNS) {
        throw new InvalidArgumentException('Los limites solicitados para XLSX no son validos');
    }
    $archiveSize = @filesize($path);
    if (!is_int($archiveSize) || $archiveSize <= 0 || $archiveSize > BLUECAT_XLSX_MAX_ARCHIVE_BYTES) {
        throw new InvalidArgumentException('El archivo XLSX esta vacio o supera el limite permitido');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($path);
    if ($opened !== true) throw new InvalidArgumentException('El archivo no es un contenedor XLSX valido');
    try {
        $entries = bluecatXlsxInspectArchive($zip);
        $workbook = bluecatXlsxReadEntry($zip, $entries, 'xl/workbook.xml');
        $relationships = bluecatXlsxReadEntry($zip, $entries, 'xl/_rels/workbook.xml.rels');
        $sheetPath = bluecatXlsxWorksheetPath($relationships, bluecatXlsxRelationshipId($workbook));
        $sheetXml = bluecatXlsxReadEntry($zip, $entries, $sheetPath);
        $sharedStrings = bluecatXlsxSharedStrings(bluecatXlsxReadEntry($zip, $entries, 'xl/sharedStrings.xml', false));
    } finally {
        $zip->close();
    }

    $reader = bluecatXlsxXmlReader($sheetXml, $sheetPath);
    $rows = [];
    $cellBudget = 0;
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
            $rowDepth = $reader->depth;
            $values = [];
            $nextColumn = 0;
            $highestColumn = -1;
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $rowDepth && $reader->localName === 'row') break;
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') continue;
                $column = bluecatXlsxColumnIndex((string)$reader->getAttribute('r')) ?? $nextColumn;
                if ($column < 0 || $column >= $maxColumns) throw new InvalidArgumentException("El XLSX supera {$maxColumns} columnas");
                $value = bluecatXlsxCellValue($reader, $sharedStrings);
                if (mb_strlen($value, 'UTF-8') > BLUECAT_XLSX_MAX_STRING_CHARACTERS) throw new InvalidArgumentException('El XLSX contiene una celda demasiado larga');
                $values[$column] = $value;
                $highestColumn = max($highestColumn, $column);
                $nextColumn = $column + 1;
            }
            if ($highestColumn < 0) continue;
            $row = array_fill(0, $highestColumn + 1, '');
            foreach ($values as $column => $value) $row[$column] = $value;
            if (!array_filter($row, static fn(string $value): bool => $value !== '')) continue;
            $cellBudget += count($row);
            if ($cellBudget > BLUECAT_XLSX_MAX_CELLS) throw new InvalidArgumentException('El XLSX contiene demasiadas celdas');
            $rows[] = $row;
            if (count($rows) > $maxRows) throw new InvalidArgumentException("El XLSX supera {$maxRows} filas");
        }
    } finally {
        $reader->close();
    }
    return $rows;
}

function bluecatXlsxXmlText($value): string {
    $text = (string)$value;
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    $text = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $text) ?? '';
    if (mb_strlen($text, 'UTF-8') > BLUECAT_XLSX_MAX_STRING_CHARACTERS) throw new InvalidArgumentException('Una celda excede 32.767 caracteres');
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bluecatXlsxColumnName(int $index): string {
    if ($index < 0 || $index >= BLUECAT_XLSX_MAX_COLUMNS) throw new InvalidArgumentException('Columna XLSX fuera de rango');
    $name = '';
    for ($value = $index + 1; $value > 0; $value = intdiv($value - 1, 26)) $name = chr(65 + (($value - 1) % 26)) . $name;
    return $name;
}

function bluecatXlsxWriteAll($stream, string $contents): void {
    $offset = 0;
    while ($offset < strlen($contents)) {
        $written = fwrite($stream, substr($contents, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('No se pudo escribir el archivo XLSX temporal');
        $offset += $written;
    }
}

/**
 * Escribe un XLSX real de una hoja. Los textos se guardan como texto (no formula),
 * mientras int/float se guardan como numeros.
 *
 * @param list<mixed> $headers
 * @param iterable<array-key,array<mixed>> $rows
 */
function bluecatXlsxWriteWorkbook(string $targetPath, array $headers, iterable $rows, string $sheetName = 'Productos'): void {
    bluecatXlsxRequireRuntime();
    $columnCount = count($headers);
    if ($columnCount < 1 || $columnCount > BLUECAT_XLSX_MAX_COLUMNS) throw new InvalidArgumentException('La cabecera XLSX tiene una cantidad de columnas no permitida');
    $sheetName = trim($sheetName);
    if ($sheetName === '' || mb_strlen($sheetName) > 31 || preg_match('~[\\/*?:\[\]]~u', $sheetName)) {
        throw new InvalidArgumentException('El nombre de la hoja XLSX no es valido');
    }

    $sheetTemp = tempnam(sys_get_temp_dir(), 'bluecat-xlsx-sheet-');
    $stringsTemp = tempnam(sys_get_temp_dir(), 'bluecat-xlsx-strings-');
    if ($sheetTemp === false || $stringsTemp === false) throw new RuntimeException('No se pudo reservar almacenamiento temporal para XLSX');
    $sheet = null;
    $strings = null;
    $zip = null;
    try {
        $sheet = fopen($sheetTemp, 'wb');
        if (!$sheet) throw new RuntimeException('No se pudo crear la hoja XLSX temporal');
        bluecatXlsxWriteAll($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');

        bluecatXlsxWriteAll($sheet, '<row r="1">');
        foreach (array_values($headers) as $column => $header) {
            $coordinate = bluecatXlsxColumnName($column) . '1';
            bluecatXlsxWriteAll($sheet, '<c r="' . $coordinate . '" t="inlineStr"><is><t xml:space="preserve">' . bluecatXlsxXmlText($header) . '</t></is></c>');
        }
        bluecatXlsxWriteAll($sheet, '</row>');

        $sharedMap = [];
        $sharedValues = [];
        $sharedReferences = 0;
        $rowNumber = 1;
        $cellBudget = $columnCount;
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Cada fila XLSX debe ser un arreglo');
            $row = array_values($row);
            if (count($row) > $columnCount) throw new InvalidArgumentException('Una fila XLSX contiene mas columnas que su cabecera');
            $rowNumber++;
            if ($rowNumber > BLUECAT_XLSX_MAX_ROWS) throw new InvalidArgumentException('El XLSX supera 5.000 filas de datos');
            $cellBudget += count($row);
            if ($cellBudget > BLUECAT_XLSX_MAX_CELLS) throw new InvalidArgumentException('El XLSX contiene demasiadas celdas');
            bluecatXlsxWriteAll($sheet, '<row r="' . $rowNumber . '">');
            foreach ($row as $column => $value) {
                if ($value === null || $value === '') continue;
                $coordinate = bluecatXlsxColumnName($column) . $rowNumber;
                if (is_int($value)) {
                    bluecatXlsxWriteAll($sheet, '<c r="' . $coordinate . '"><v>' . $value . '</v></c>');
                } elseif (is_float($value)) {
                    if (!is_finite($value)) throw new InvalidArgumentException('El XLSX no admite numeros infinitos o NaN');
                    $number = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                    bluecatXlsxWriteAll($sheet, '<c r="' . $coordinate . '"><v>' . $number . '</v></c>');
                } else {
                    if (is_bool($value)) $value = $value ? '1' : '0';
                    elseif (!is_string($value) && !($value instanceof Stringable)) throw new InvalidArgumentException('El XLSX solo admite valores escalares');
                    $text = (string)$value;
                    bluecatXlsxXmlText($text); // valida UTF-8, controles y longitud antes de indexar
                    $key = 's:' . $text;
                    if (!array_key_exists($key, $sharedMap)) {
                        $sharedMap[$key] = count($sharedValues);
                        $sharedValues[] = $text;
                    }
                    $sharedReferences++;
                    bluecatXlsxWriteAll($sheet, '<c r="' . $coordinate . '" t="s"><v>' . $sharedMap[$key] . '</v></c>');
                }
            }
            bluecatXlsxWriteAll($sheet, '</row>');
        }
        bluecatXlsxWriteAll($sheet, '</sheetData></worksheet>');
        fclose($sheet);
        $sheet = null;

        $strings = fopen($stringsTemp, 'wb');
        if (!$strings) throw new RuntimeException('No se pudo crear la tabla de textos XLSX');
        bluecatXlsxWriteAll($strings, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $sharedReferences . '" uniqueCount="' . count($sharedValues) . '">');
        foreach ($sharedValues as $value) bluecatXlsxWriteAll($strings, '<si><t xml:space="preserve">' . bluecatXlsxXmlText($value) . '</t></si>');
        bluecatXlsxWriteAll($strings, '</sst>');
        fclose($strings);
        $strings = null;

        $zip = new ZipArchive();
        $opened = $zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            $zip = null;
            throw new RuntimeException('No se pudo crear el contenedor XLSX');
        }
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
        $rootRelationships = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . bluecatXlsxXmlText($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $workbookRelationships = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles><dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/></styleSheet>';
        $fixedEntries = [
            '[Content_Types].xml'=>$contentTypes,
            '_rels/.rels'=>$rootRelationships,
            'xl/workbook.xml'=>$workbook,
            'xl/_rels/workbook.xml.rels'=>$workbookRelationships,
            'xl/styles.xml'=>$styles,
        ];
        foreach ($fixedEntries as $name => $contents) {
            if (!$zip->addFromString($name, $contents)) throw new RuntimeException("No se pudo agregar {$name} al XLSX");
        }
        if (!$zip->addFile($sheetTemp, 'xl/worksheets/sheet1.xml') || !$zip->addFile($stringsTemp, 'xl/sharedStrings.xml')) {
            throw new RuntimeException('No se pudieron agregar los datos al XLSX');
        }
        if (!$zip->close()) throw new RuntimeException('No se pudo finalizar el XLSX');
        $zip = null;
    } catch (Throwable $error) {
        if ($sheet !== null && is_resource($sheet)) fclose($sheet);
        if ($strings !== null && is_resource($strings)) fclose($strings);
        if ($zip instanceof ZipArchive) {
            try { $zip->close(); } catch (Throwable) {}
        }
        @unlink($targetPath);
        throw $error;
    } finally {
        @unlink($sheetTemp);
        @unlink($stringsTemp);
    }
}

/** @param list<mixed> $headers @param iterable<array-key,array<mixed>> $rows */
function bluecatXlsxBuildWorkbook(array $headers, iterable $rows, string $sheetName = 'Productos'): string {
    $temporary = tempnam(sys_get_temp_dir(), 'bluecat-xlsx-');
    if ($temporary === false) throw new RuntimeException('No se pudo reservar un archivo XLSX temporal');
    try {
        bluecatXlsxWriteWorkbook($temporary, $headers, $rows, $sheetName);
        $contents = file_get_contents($temporary);
        if (!is_string($contents) || $contents === '') throw new RuntimeException('No se pudo leer el XLSX generado');
        return $contents;
    } finally {
        @unlink($temporary);
    }
}
