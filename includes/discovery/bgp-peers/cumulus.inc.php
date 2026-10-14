<?php

use App\Facades\LibrenmsConfig;
use LibreNMS\Util\IP;

$bgpPeers = \SnmpQuery::enumStrings()->hideMib()->walk('CUMULUS-BGPVRF-MIB::bgpPeerTable')->mapTable(
    function ($data, $vrfId) {
        $data['vrfId'] = $vrfId;
        $data['ifIndex'] = ($data['bgpPeerIfindex'] ?? 0) ?: null; // the table index only holds part of it

        return $data;
    });

$vrfs = DeviceCache::getPrimary()->vrfs()->select('vrf_id', 'vrf_oid')->get();
$seenPeerID = null;

foreach ($bgpPeers as $bgpPeer) {
    $bgpLocalAs = \SnmpQuery::hideMib()->get("CUMULUS-BGPVRF-MIB::bgpLocalAs.{$bgpPeer['vrfId']}")->value();
    $astext = \LibreNMS\Util\AutonomousSystem::get($bgpPeer['bgpPeerRemoteAs'])->name();
    echo "AS$bgpLocalAs \n";
    $bgpPeer['bgpPeerIdentifier'] = $bgpPeer['bgpPeerRemoteAddr'] ?? $bgpPeer['bgpPeerIdentifier']; // bgpPeerIdentifier is not unique.
    echo "BGP Peer {$bgpPeer['bgpPeerIdentifier']} ";

    $vrf = $vrfs->where('vrf_oid', $bgpPeer['vrfId'])->first();
    if (is_null($vrf)) {
        echo "VRF {$bgpPeer['vrfId']} not found, skipping peer discovery.\n";
        continue;
    }
    $vrfId = $vrf->vrf_id;

    if (! DeviceCache::getPrimary()->bgppeers()->where('bgpPeerIdentifier', $bgpPeer['bgpPeerIdentifier'])->where('vrf_id', $vrfId)->exists()) {
        $peers = [
            'vrf_id' => $vrfId,
            'bgpPeerIdentifier' => $bgpPeer['bgpPeerIdentifier'],
            'context_name' => '',
            'bgpPeerRemoteAs' => $bgpPeer['bgpPeerRemoteAs'],
            'bgpPeerState' => $bgpPeer['bgpPeerState'],
            'bgpPeerAdminStatus' => $bgpPeer['bgpPeerAdminStatus'],
            'bgpLocalAddr' => $bgpPeer['bgpPeerLocalAddr'],
            'bgpPeerRemoteAddr' => $bgpPeer['bgpPeerRemoteAddr'],
            'bgpPeerInUpdates' => $bgpPeer['bgpPeerInUpdates'],
            'bgpPeerOutUpdates' => $bgpPeer['bgpPeerOutUpdates'],
            'bgpPeerInTotalMessages' => $bgpPeer['bgpPeerInTotalMessages'],
            'bgpPeerOutTotalMessages' => $bgpPeer['bgpPeerOutTotalMessages'],
            'bgpPeerFsmEstablishedTime' => $bgpPeer['bgpPeerFsmEstablishedTime'],
            'bgpPeerInUpdateElapsedTime' => $bgpPeer['bgpPeerInUpdateElapsedTime'],
            'bgpPeerIface' => $bgpPeer['ifIndex'],
            'bgpPeerDescr' => $bgpPeer['bgpPeerDesc'],
            'astext' => $astext,
        ];

        DeviceCache::getPrimary()->bgppeers()->create($peers);

        // a peer that never came up reports bgpPeerRemoteAddr "Unknown"
        if (LibrenmsConfig::get('autodiscovery.bgp') && IP::isValid($bgpPeer['bgpPeerRemoteAddr'])) {
            $name = gethostbyaddr($bgpPeer['bgpPeerRemoteAddr']);
            discover_new_device($name, $device, 'BGP');
        }
        echo '+';
    } else {
        $peers = [
            'bgpPeerRemoteAs' => $bgpPeer['bgpPeerRemoteAs'],
            'bgpPeerIface' => $bgpPeer['ifIndex'],
            'astext' => $astext,
        ];
        $affected = DeviceCache::getPrimary()->bgppeers()->where('bgpPeerIdentifier', $bgpPeer['bgpPeerIdentifier'])->where('vrf_id', $vrfId)->update($peers);
        echo str_repeat('.', $affected);
    }
    $seenPeerID[] = DeviceCache::getPrimary()->bgppeers()->where('bgpPeerIdentifier', $bgpPeer['bgpPeerIdentifier'])->where('vrf_id', $vrfId)->select('bgpPeer_id')->orderBy('bgpPeer_id', 'ASC')->first()->bgpPeer_id;
}

if (! is_null($seenPeerID)) {
    $deleted = DeviceCache::getPrimary()->bgppeers()->whereNotIn('bgpPeer_id', $seenPeerID)->delete();
    echo str_repeat('-', $deleted);
}

unset($bgpPeers);
