<?php

namespace Spawnflow\Http;

use Illuminate\Http\Request;
use Illuminate\Http\StreamedEvent;
use Illuminate\Routing\Controller;
use Illuminate\Support\Sleep;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Support\SubjectVersion;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The opt-in SSE invalidation channel:
 *
 *   GET /spawnflow/events?subjects=posts,comments
 *
 * Pushes `change` events ({subject, version}) whenever a subject's
 * version moves — signals only, never state. Clients refetch through
 * the schema/CRUD endpoints they already use; a dropped connection
 * degrades to non-live, never to wrong data (SSE auto-reconnects).
 *
 * Each open stream holds a PHP worker (standard SSE-on-FPM economics) —
 * size worker pools accordingly, or terminate idle streams via
 * spawnflow.events_max_polls and let EventSource reconnect.
 */
class EventsController extends Controller
{
    public function show(Request $request): StreamedResponse
    {
        $registry = app(SubjectRegistry::class);
        $registered = array_keys($registry->all());

        $requested = array_filter(explode(',', (string) $request->query('subjects', '')));
        $subjects = $requested === []
            ? $registered
            : array_values(array_intersect(array_map(mb_strtolower(...), $requested), $registered));

        $interval = max(0, (int) config('spawnflow.events_poll_interval', 2));
        $maxPolls = config('spawnflow.events_max_polls');

        // Reconnect resume: ?since[posts]=3 replays anything newer than
        // the client's last seen version, so changes missed while
        // disconnected surface immediately on the new stream.
        $since = (array) $request->query('since', []);

        return response()->eventStream(function () use ($subjects, $interval, $maxPolls, $since) {
            $seen = SubjectVersion::snapshot($subjects);
            foreach ($since as $subject => $version) {
                if (array_key_exists($subject, $seen)) {
                    $seen[$subject] = (int) $version;
                }
            }
            $polls = 0;

            while ($maxPolls === null || $polls < (int) $maxPolls) {
                $current = SubjectVersion::snapshot($subjects);

                foreach ($current as $subject => $version) {
                    if ($version > $seen[$subject]) {
                        yield new StreamedEvent(event: 'change', data: json_encode([
                            'subject' => $subject,
                            'version' => $version,
                        ]));
                    }
                }

                $seen = $current;
                $polls++;

                if ($interval > 0 && ($maxPolls === null || $polls < (int) $maxPolls)) {
                    Sleep::sleep($interval);
                }

                if (connection_aborted()) {
                    return;
                }
            }
        });
    }
}
