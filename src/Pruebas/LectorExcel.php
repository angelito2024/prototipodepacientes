<?php
declare(strict_types=1);

namespace Centro\Pruebas;

use RuntimeException;

/**
 * Lee un .xlsx / .xlsm sin librerías externas.
 *
 * Un libro de Excel moderno es un ZIP con XML adentro. El PHP de Laragon no
 * trae la extensión zip, así que aquí se recorre el directorio central del
 * ZIP a mano y se descomprime con gzinflate(), que sí está (zlib).
 *
 * Solo hace falta para cargar una prueba nueva desde el Excel del psicólogo.
 * El sistema en marcha no lo usa: trabaja con la definición ya guardada.
 */
final class LectorExcel
{
    /** @var array<string,string> ruta dentro del ZIP => contenido */
    private array $archivos = [];
    /** @var list<string> */
    private array $textos = [];

    public function __construct(string $ruta)
    {
        if (!is_file($ruta)) {
            throw new RuntimeException("No encuentro el archivo: $ruta");
        }
        $this->abrirZip((string) file_get_contents($ruta));
        $this->cargarTextosCompartidos();
    }

    /**
     * Recorre el "directorio central" del ZIP, que está al final del
     * archivo y lista cada entrada con su posición y su tamaño.
     */
    private function abrirZip(string $bin): void
    {
        // Fin del directorio central: firma PK\5\6. Se busca desde atrás
        // porque puede llevar un comentario de longitud variable.
        $fin = strrpos($bin, "PK\x05\x06");
        if ($fin === false) {
            throw new RuntimeException('El archivo no parece un Excel (.xlsx/.xlsm) válido.');
        }
        $cab = unpack('vdisco/vdiscoCD/vnLocal/vnTotal/Vtam/Vdesplazamiento', substr($bin, $fin + 4, 16));
        $p = (int) $cab['desplazamiento'];

        for ($i = 0; $i < (int) $cab['nTotal']; $i++) {
            if (substr($bin, $p, 4) !== "PK\x01\x02") {
                break;
            }
            $e = unpack(
                'vversion/vnecesita/vbanderas/vmetodo/vhora/vfecha/Vcrc/Vcomprimido/VsinComprimir'
                . '/vnombre/vextra/vcomentario/vdisco/vinterno/Vexterno/Vlocal',
                substr($bin, $p + 4, 42)
            );
            $nombre = substr($bin, $p + 46, (int) $e['nombre']);
            $p += 46 + (int) $e['nombre'] + (int) $e['extra'] + (int) $e['comentario'];

            // La cabecera local repite el nombre y los campos extra, y su
            // longitud no tiene por qué coincidir con la del directorio.
            $l = (int) $e['local'];
            $loc = unpack('vnombre/vextra', substr($bin, $l + 26, 4));
            $datos = substr($bin, $l + 30 + (int) $loc['nombre'] + (int) $loc['extra'], (int) $e['comprimido']);

            $this->archivos[$nombre] = match ((int) $e['metodo']) {
                0 => $datos,                       // guardado sin comprimir
                8 => (string) gzinflate($datos),   // deflate
                default => throw new RuntimeException(
                    "El Excel usa una compresión que no sé leer (método {$e['metodo']})."
                ),
            };
        }
    }

    /** Excel guarda los textos repetidos una sola vez, en sharedStrings. */
    private function cargarTextosCompartidos(): void
    {
        $xml = $this->archivos['xl/sharedStrings.xml'] ?? null;
        if ($xml === null) {
            return;
        }
        $x = simplexml_load_string($xml);
        foreach ($x->si as $si) {
            if (isset($si->t)) {
                $this->textos[] = (string) $si->t;
                continue;
            }
            // Texto con varios formatos: viene partido en trozos <r><t>.
            $t = '';
            foreach ($si->r as $r) {
                $t .= (string) $r->t;
            }
            $this->textos[] = $t;
        }
    }

    /** Nombre de hoja => archivo XML que la contiene. */
    public function hojas(): array
    {
        $libro = simplexml_load_string($this->archivos['xl/workbook.xml']);
        $rels  = simplexml_load_string($this->archivos['xl/_rels/workbook.xml.rels']);

        $destino = [];
        foreach ($rels->Relationship as $r) {
            $destino[(string) $r['Id']] = basename((string) $r['Target']);
        }
        $out = [];
        foreach ($libro->sheets->sheet as $h) {
            $id = (string) $h->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            $out[(string) $h['name']] = $destino[$id] ?? null;
        }
        return $out;
    }

    /**
     * Celdas de una hoja: [fila][columna] => ['v' => valor, 'f' => fórmula].
     * La fórmula hace falta porque en este Excel la clave de corrección de
     * cada escala está escrita ahí, no en ninguna celda de datos.
     */
    public function celdas(string $archivoHoja): array
    {
        $xml = $this->archivos["xl/worksheets/$archivoHoja"] ?? null;
        if ($xml === null) {
            throw new RuntimeException("No encuentro la hoja $archivoHoja dentro del Excel.");
        }
        $x = simplexml_load_string($xml);
        $celdas = [];
        foreach ($x->sheetData->row as $fila) {
            $n = (int) $fila['r'];
            foreach ($fila->c as $c) {
                $col = preg_replace('/\d/', '', (string) $c['r']);
                $tipo = (string) $c['t'];
                $v = isset($c->v) ? (string) $c->v : null;
                if ($tipo === 's' && $v !== null) {
                    $v = $this->textos[(int) $v] ?? '';
                } elseif ($tipo === 'inlineStr') {
                    $v = (string) $c->is->t;
                }
                $celdas[$n][$col] = ['v' => $v, 'f' => isset($c->f) ? (string) $c->f : null];
            }
        }
        return $celdas;
    }
}
