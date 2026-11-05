<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Pembatalan konversi quotation ditolak; pesannya siap ditampilkan ke pengguna.
 */
class QuotationUnconvertException extends RuntimeException
{
}
