<?php

namespace Tests\Unit;

use App\Helpers\ClientLabel;
use App\Models\Deal;
use App\Models\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientLabelTest extends TestCase
{
    #[DataProvider('klien')]
    public function test_format_pic_dan_perusahaan(?string $pic, ?string $company, string $harapan): void
    {
        $this->assertSame($harapan, ClientLabel::of($pic, $company));
    }

    public static function klien(): array
    {
        return [
            'lengkap'               => ['Budi Santoso', 'PT Nestory Indonesia', 'Budi Santoso - PT Nestory Indonesia'],
            'tanpa PIC'             => [null, 'PT Nestory Indonesia', 'PT Nestory Indonesia'],
            'PIC berisi "-"'        => ['-', 'PT Nestory Indonesia', 'PT Nestory Indonesia'],
            'perorangan'            => ['Budi Santoso', '', 'Budi Santoso'],
            'nama sama di keduanya' => ['Budi Santoso', 'budi santoso', 'budi santoso'],
            'kosong semua'          => [' ', null, '-'],
        ];
    }

    public function test_project_memakai_data_client_bila_kosong(): void
    {
        $project = (new Project)->forceFill(['id' => 7, 'nama_project' => 'Video Profile']);
        $project->setRelation('deal', (new Deal)->forceFill(['nama_pic' => 'Sari Dewi', 'nama_perusahaan' => 'PT Nestory Indonesia']));

        $this->assertSame('Sari Dewi - PT Nestory Indonesia | Video Profile', ClientLabel::project($project));

        $project->forceFill(['nama_pic' => 'Budi Santoso']);
        $this->assertSame('Budi Santoso - PT Nestory Indonesia | Video Profile', ClientLabel::project($project), 'PIC di project didahulukan.');

        $this->assertSame('Tanpa project', ClientLabel::project(null));
        $this->assertSame('Project #9', ClientLabel::project((new Project)->forceFill(['id' => 9])->setRelation('deal', null)));
    }
}
