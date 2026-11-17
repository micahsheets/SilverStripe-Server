<?php

namespace App\Auctioneer\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

class AuctionPanelStation extends DataObject
{
    private static string $table_name = 'AuctionPanelStation';
    private static string $singular_name = 'Lane Touch Panel';
    private static string $plural_name = 'Lane Touch Panels';
    private static string $default_sort = 'SortOrder ASC, Title ASC, DTXChannel ASC';

    private static array $db = [
        'Title'          => 'Varchar(100)',
        'DTXChannel'     => 'Int',
        'PanelIPAddress' => 'Varchar(45)',
        'SortOrder'      => 'Int',
        'LastLoginAt'    => 'Datetime',
    ];

    private static array $defaults = [
        'DTXChannel' => 1,
        'SortOrder'  => 0,
    ];

    private static array $has_one = [
        'Lane'             => AuctionLane::class,
        'ActiveAuctioneer' => AuctioneerProfile::class,
    ];

    private static array $summary_fields = [
        'Lane.Title'             => 'Lane',
        'Title'                  => 'Panel Position',
        'DTXChannel'             => 'DTX Mic Input (1-64)',
        'PanelIPAddress'         => '4" Panel IP (Optional)',
        'ActiveAuctioneer.Title' => 'Logged-In Auctioneer',
        'KioskURL'               => 'Direct Kiosk URL',
    ];

    public function getKioskURL(): string
    {
        return '/panel/auctioneer?station=' . (int) $this->ID;
    }

    public function getCMSFields(): FieldList
    {
        $maxCh = 64;
        $lane = $this->Lane();
        if ($lane && $lane->exists() && $lane->DTXDevice()->exists()) {
            $maxCh = $lane->DTXDevice()->getChannelCapacity();
        }

        $channelOptions = [];
        for ($ch = 1; $ch <= $maxCh; $ch++) {
            $channelOptions[$ch] = "Mic Input {$ch} (Channel {$ch})";
        }

        return FieldList::create(
            DropdownField::create('LaneID', 'Auction Lane', AuctionLane::get()->map('ID', 'Title'))
                ->setEmptyString('-- Select Lane --'),
            TextField::create('Title', 'Panel Position Name (e.g. Block Left, Block Right, Pod 3)'),
            DropdownField::create('DTXChannel', "DTX Microphone Input Channel (1 - {$maxCh})", $channelOptions)
                ->setDescription('Automatically scopes to the assigned DTX unit capacity (4, 8, 32, or 64 channels)'),
            TextField::create('PanelIPAddress', '4" Touch Panel IP (Optional auto-bind)'),
            NumericField::create('SortOrder', 'Sort Order')->setHTML5(true),
            ReadonlyField::create('KioskURLDisplay', 'Direct 4" Touchscreen URL', $this->getKioskURL())
        );
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $ch = (int) $this->DTXChannel;
        $maxCh = 64;
        $lane = $this->Lane();
        if ($lane && $lane->exists() && $lane->DTXDevice()->exists()) {
            $maxCh = $lane->DTXDevice()->getChannelCapacity();
        }

        if ($ch < 1 || $ch > $maxCh) {
            $result->addFieldError('DTXChannel', "DTX Channel must be between 1 and {$maxCh} for the assigned DTX model.");
        }
        if (trim((string) $this->Title) === '') {
            $this->Title = "Mic {$ch} Panel";
        }
        if ($this->LaneID) {
            $dup = self::get()->filter([
                'LaneID'     => (int) $this->LaneID,
                'DTXChannel' => $ch,
            ])->exclude('ID', (int) $this->ID)->first();
            if ($dup) {
                $result->addFieldError('DTXChannel', "Channel {$ch} on this lane is already assigned to {$dup->Title}.");
            }
        }
        return $result;
    }
}
