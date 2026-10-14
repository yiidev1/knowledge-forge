<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertSame;

/**
 * Every address on the agent practice surface is behind a gate, and the gate is the agent one.
 *
 * ## What this proves, and why it is worth a suite of its own
 *
 * A route added to the wrong group is the quietest possible mistake: the page works, the tests that
 * exercise it pass, and it is simply reachable by people it was never meant for. Nothing else in the
 * build notices. So each of the ten addresses is requested with no session at all and must answer with
 * a redirect to the **agent** login — not the administrator one, which would mean the route landed in
 * an administrator group, and not a 200, which would mean it landed in none.
 *
 * Driven in-process, because that is enough: what is being checked is the routing table and the
 * middleware stack, neither of which needs a browser.
 *
 * ## What it deliberately does not cover
 *
 * The signed-in agent's page. Agent authentication calls a live Order58 host that this environment
 * does not have, so no automated suite here can hold an agent session. The read-only rendering is
 * enforced by {@see \App\AudioToText\Domain\ConversationCapabilities} and by the endpoints simply not
 * being mounted — both covered elsewhere — and the rendered page is verified by hand.
 */
final class AgentPracticeOrdersGateCest
{
    private const STORE = '987655941';
    private const JOB = 'c0ffee00c0ffee00c0ffee00c0ffee00';
    private const ORDER = '16950001';

    /**
     * Every GET on the surface, and the one POST.
     *
     * @return list<array{string, string}> method and path
     */
    private static function addresses(): array
    {
        $store = '/agent/practice-orders/store/' . self::STORE;
        $order = $store . '/order/' . self::ORDER;

        return [
            ['GET', '/agent/practice-orders'],
            ['GET', $store],
            ['GET', $store . '/arriving'],
            ['GET', $store . '/group/order%3A' . self::ORDER . '/transcripts'],
            ['GET', $store . '/group/order%3A' . self::ORDER . '/recordings'],
            ['GET', '/agent/practice-orders/job/' . self::JOB . '/original/file'],
            ['GET', '/agent/practice-orders/job/' . self::JOB . '/ai-audio/file'],
            ['GET', '/agent/practice-orders/job/' . self::JOB . '/review/fragment'],
            ['GET', '/agent/practice-orders/job/' . self::JOB . '/status'],
            ['GET', $order . '/demo-orders'],
            ['GET', $order . '/demo/1'],
            ['GET', $order . '/demo/1/receipt'],
        ];
    }

    public function everyPracticeAddressRedirectsAGuestToTheAgentLogin(FunctionalTester $tester): void
    {
        foreach (self::addresses() as [$method, $path]) {
            $response = $tester->sendRequest(new ServerRequest(method: $method, uri: $path));

            assertSame(302, $response->getStatusCode(), $method . ' ' . $path);
            assertSame(
                '/agent/login',
                $response->getHeaderLine('Location'),
                $method . ' ' . $path . ' must be behind the AGENT gate, not another realm\'s',
            );
        }
    }

    /**
     * The one write, refused — and refused earlier than the gate.
     *
     * The global CSRF middleware sits in front of the router, so an unsafe request with no token is
     * answered 422 before anything decides which route it was for. That ordering is deliberate and
     * worth pinning: an unauthenticated POST never reaches an action, and never learns from the
     * response whether the address it guessed exists.
     */
    public function theDemoUrlPostIsRefusedBeforeItReachesAnything(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(
            new ServerRequest(method: 'POST', uri: '/agent/practice-orders/store/' . self::STORE . '/demo-url'),
        );

        assertSame(422, $response->getStatusCode());
    }

    /**
     * **Nothing that writes was mounted for agents.**
     *
     * Each of these exists on the administrator surface and must have no agent address at all. A 404
     * means the router knows no such route — the strongest form of "not available", stronger than a
     * gate, because there is nothing to gate.
     *
     * Only the GET ones can be proved this way. An unsafe method is answered 422 by the CSRF
     * middleware in front of the router, so a POST cannot distinguish "no such route" from "no token";
     * those are covered by {@see everyWritingAddressIsRefused()} below and, exactly, by the route-name
     * test in the unit suite.
     */
    public function noReadableMutationAddressExistsUnderTheAgentPrefix(FunctionalTester $tester): void
    {
        $store = '/agent/practice-orders/store/' . self::STORE;

        $readableMutations = [
            $store . '/group/order%3A' . self::ORDER . '/tts-options',
            '/agent/practice-orders/job/' . self::JOB . '/download',
            '/agent/practice-orders/job/' . self::JOB . '/review',
            '/agent/practice-orders/job/' . self::JOB . '/review/history',
            '/agent/practice-orders/job/' . self::JOB . '/conversation',
            '/agent/practice-orders/job/' . self::JOB . '/original',
        ];

        foreach ($readableMutations as $path) {
            $response = $tester->sendRequest(new ServerRequest(uri: $path));

            assertSame(404, $response->getStatusCode(), $path . ' must not be a route at all');
        }
    }

    /**
     * And every writing address is refused, whether or not it exists.
     *
     * 422 from the CSRF middleware for all of them: the ones that are not routes never reach the
     * router, and the ones that are never reach their action. Either way nothing is written, which is
     * the property that matters here; *which* of the two it was is pinned exactly by the unit test
     * over the route table.
     */
    public function everyWritingAddressIsRefused(FunctionalTester $tester): void
    {
        $store = '/agent/practice-orders/store/' . self::STORE;

        $writes = [
            $store,                                             // upload
            $store . '/topic',                                  // assign a topic
            $store . '/topic/bulk',                             // bulk assign
            $store . '/group/order%3A' . self::ORDER . '/replace',
            '/agent/practice-orders/sync-demo-orders',
            '/agent/practice-orders/job/' . self::JOB . '/transcribe',
            '/agent/practice-orders/job/' . self::JOB . '/ai-audio/generate',
            '/agent/practice-orders/job/' . self::JOB . '/review/confirm',
            '/agent/practice-orders/job/' . self::JOB . '/review/turn/0/text',
        ];

        foreach ($writes as $path) {
            $response = $tester->sendRequest(new ServerRequest(method: 'POST', uri: $path));

            assertSame(422, $response->getStatusCode(), 'POST ' . $path . ' must change nothing');
        }
    }

    /** And Topic Management is an administrator page, whatever prefix is tried. */
    public function topicManagementHasNoAgentAddress(FunctionalTester $tester): void
    {
        foreach (['/agent/topics', '/agent/practice-orders/topics'] as $path) {
            assertSame(404, $tester->sendRequest(new ServerRequest(uri: $path))->getStatusCode(), $path);
        }
    }
}
