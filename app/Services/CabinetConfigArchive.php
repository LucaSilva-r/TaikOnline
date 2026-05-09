<?php

namespace App\Services;

use App\Models\Cabinet;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class CabinetConfigArchive
{
    private const CONFIG_PATH = 'USRDIR/data/config/S11100-1/chassisinfo.xml';

    private const SERIAL_TXT_PATH = 'USRDIR/dongle_serial.txt';

    public function streamDownload(Cabinet $cabinet): StreamedResponse
    {
        if ($cabinet->isDefault()) {
            throw new RuntimeException('Default cabinet has no downloadable config.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cab_');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create temp file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot open zip.');
        }

        $zip->addFromString(self::SERIAL_TXT_PATH, $cabinet->serial);
        $zip->addFromString(self::CONFIG_PATH, $this->buildChassisInfoXml($cabinet->serial));
        $zip->close();

        $filename = "taikonline-cabinet-{$cabinet->serial}.zip";

        return new StreamedResponse(function () use ($tmp): void {
            readfile($tmp);
            @unlink($tmp);
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function buildChassisInfoXml(string $serial): string
    {
        $defaultBlock = $this->buildInfoBlock(Cabinet::DEFAULT_SERIAL);
        $userBlock = $this->buildInfoBlock($serial);

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<!DOCTYPE boost_serialization>
<boost_serialization signature="serialization::archive" version="10">
\t<Chassis class_id="0" tracking_level="0" version="0">
\t\t<Header class_id="1" tracking_level="0" version="0">
\t\t\t<version>538510357</version>
\t\t\t<size>2</size>
\t\t</Header>
{$defaultBlock}
{$userBlock}
\t</Chassis>
</boost_serialization>
XML;
    }

    private function buildInfoBlock(string $serial): string
    {
        return <<<XML
\t\t<Info>
\t\t\t<serial>{$serial}</serial>
\t\t\t<is_promotion>1</is_promotion>
\t\t\t<force_offline>0</force_offline>
\t\t\t<force_freeplay>1</force_freeplay>
\t\t\t<force_autoplay>0</force_autoplay>
\t\t\t<force_serious>1</force_serious>
\t\t\t<force_musicinfo_allrelease>1</force_musicinfo_allrelease>
\t\t\t<force_burst_mode>1</force_burst_mode>
\t\t\t<ignore_network_authentication>1</ignore_network_authentication>
\t\t\t<ignore_network_connection>1</ignore_network_connection>
\t\t\t<ignore_closetime>0</ignore_closetime>
\t\t\t<ignore_nblinepoint>0</ignore_nblinepoint>
\t\t\t<ignore_mucha_invalid_enforced>1</ignore_mucha_invalid_enforced>
\t\t\t<disable_countdowntimer>1</disable_countdowntimer>
\t\t\t<anytime_tokkun>1</anytime_tokkun>
\t\t\t<anytime_dani>1</anytime_dani>
\t\t\t<force_dani>1</force_dani>
\t\t\t<anytime_ghostbattle>1</anytime_ghostbattle>
\t\t</Info>
XML;
    }
}
