<?php
declare(strict_types=1);

namespace Centro;

/**
 * Llegó la lista de una colección vacía, pero en la base hay filas vivas.
 *
 * Eso casi nunca significa «el usuario borró todo»: significa que el panel
 * no pudo leer y se quedó con la copia vacía en memoria. Borrar todo a
 * partir de esa copia es convertir una falla de lectura en pérdida de
 * datos, y ese es el único error de este sistema que el usuario no puede
 * deshacer solo.
 *
 * Si de verdad hay que vaciar una colección, se borra fila por fila desde
 * la pantalla: eso es una decisión, no un accidente.
 */
final class VaciadoSospechoso extends \RuntimeException
{
}