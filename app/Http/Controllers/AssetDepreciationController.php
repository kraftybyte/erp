<?php

namespace App\Http\Controllers;

use App\Services\AssetDepreciation;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetDepreciationController extends Controller
{
    public function __invoke(AssetDepreciation $depreciation, int $tahun): BinaryFileResponse
    {
        abort_unless(AssetDepreciation::visibleTo(auth()->user()), 403);

        return response()
            ->download($depreciation->excel($tahun), "penyusutan-aset-{$tahun}.xlsx")
            ->deleteFileAfterSend();
    }
}
