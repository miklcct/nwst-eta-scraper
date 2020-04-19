#!/usr/bin/php
<?php
declare(strict_types = 1);

use GuzzleHttp\Client;
use Miklcct\Nwst\Api;
use Miklcct\Nwst\ApiFactory;
use Miklcct\Nwst\Model\Eta;
use Miklcct\Nwst\Model\NoEta;
use Miklcct\Nwst\Model\Rdv;
use Miklcct\Nwst\Model\Route;
use Miklcct\Nwst\Model\RouteStop;
use Miklcct\Nwst\Model\VariantInfo;

require __DIR__ . '/vendor/autoload.php';

function get_eta(Api $api, string $routeNumber, int $sequence, int $stop_id, Rdv $rdv, string $bound) {
    for ($i = 0; $i < 3; ++$i) {
        try {
            return $api->getEtaList($routeNumber, $sequence, $stop_id, $rdv, $bound)->wait();
        } catch (Throwable $e) {
        }
    }
    throw $e;
}

/**
 * @param Eta[] $pending_etas
 */
function show_old_etas(array &$pending_etas) {
    foreach ($pending_etas as &$old_eta) {
        if ($old_eta !== NULL && time() - $old_eta->time->getTimestamp() >= -60) {
            fputs(STDOUT,
                $old_eta->time->format('Y-m-d H:i:s')
                . "\t$old_eta->rdv\t$old_eta->destination\t$old_eta->providingCompany\t$old_eta->message\n"
            );
        }
        $old_eta = NULL;
    }
    fflush(STDOUT);
}

if ($argc < 3) {
    fputs(STDERR, "Usage: $argv[0] <route_number> <bound> [RDV] [sequence]\n");
    fputs(STDERR, "Bound is I or O, standing for inbound or outbound respectivelyn\n");
    fputs(STDERR, "RDV is in form of 970-SOU-1, default is the first variant got from the variant list.\n");
    fputs(STDERR, "sequence is to specify which stop to get the ETA, default is 1.\n");
    exit(1);
}

$api = (new ApiFactory(new Client(['timeout' => 5])))(Api::ENGLISH);
$routeNumber = $argv[1];
$bound = $argv[2];
/** @var Route|null $route */
$route = array_values(
    array_filter(
        $api->getRouteList()->wait()
        , static function (Route $r) use ($bound, $routeNumber) {
            return $r->routeNumber === $routeNumber && $r->bound === $bound;
        }
    )
)[0] ?? NULL;
if ($route === NULL) {
    throw new InvalidArgumentException('The route / direction does not exist.');
}
$rdv = $argc > 3 ? Rdv::parse($argv[3]) : $api->getVariantList($route->id)->wait()[0]->rdv;
$sequence = filter_var($argv[4] ?? 1, FILTER_VALIDATE_INT);
if ($sequence === FALSE) {
    throw new InvalidArgumentException('Sequence must be an integer.');
}

$stop_list = $api->getStopList(new VariantInfo($route->company, $rdv, 1, 99, 10000, $bound))->wait();
/** @var RouteStop|null $stop */
$stop = array_values(
    array_filter(
        $stop_list
        , static function (RouteStop $s) use ($sequence) {
            return $s->sequence === $sequence;
        }
    )
)[0] ?? NULL;

if ($stop === NULL) {
    throw new InvalidArgumentException('The stop does not exist');
}

fputs(STDOUT, "Scraping ETA for $routeNumber towards $route->destination at stop $stop->stopName.\n");
fflush(STDOUT);

/** @var Eta[] $pending_etas */
$pending_etas = [];
$stop_id = $stop->stopId;

do {
    $etas = get_eta($api, $routeNumber, $sequence, $stop_id, $rdv, $bound);
    if (!$etas instanceof NoEta) {
        foreach ($etas as &$eta) {
            foreach ($pending_etas as &$old_eta) {
                if ($old_eta !== NULL && abs($eta->time->getTimestamp() - $old_eta->time->getTimestamp()) <= 60) {
                    $old_eta = NULL;
                }
            }
        }
    }

    show_old_etas($pending_etas);

    if (!$etas instanceof NoEta) {
        $pending_etas = $etas;
    }
    $pending_etas = array_values(array_filter($pending_etas));

    usort(
        $pending_etas
        , function (Eta $a, Eta $b) {
            return $a->time->getTimestamp() <=> $b->time->getTimestamp();
        }
    );

    sleep(10);
} while ($pending_etas !== []);

fputs(STDOUT, 'Scraping finished at ' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . ".\n");