<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

/**
 * MariaDB overlaps heavily with MySQL on the comparison surface modelled here
 * (collation-aware string equality, tinyint booleans). A distinct subclass
 * exists so per-driver behaviour can diverge — e.g. when M17+ adds JSON /
 * RETURNING semantics that differ between the two engines.
 */
final class MariaDbSemantics extends MySqlSemantics {}
