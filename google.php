<?php

/**
 * File: google.php
 * Project: PrintPoint
 * User: brandon
 * Date: 3/8/18
 */

require_once __DIR__ . '/vendor/autoload.php';

use Carbon\Carbon;

/**
 * @param $clientSecret
 * @param null $accessToken
 * @param null $refreshToken
 * @return Google_Client
 * @throws Google_Service_Exception
 */
function createClient($clientSecret, $accessToken = null, $refreshToken = null) {
    $client = new Google_Client();

    try {
        $client->setApplicationName('PrintPoint');
        $client->setScopes([Google_Service_Calendar::CALENDAR]);
        $client->setAuthConfig(json_decode($clientSecret, true));
        $client->setAccessType('offline');

        if ($accessToken !== null && $refreshToken !== null) {
            $client->setAccessToken($accessToken);

            if ($client->isAccessTokenExpired()) {
                $client->fetchAccessTokenWithRefreshToken($refreshToken);
            }
        }
    } catch(Exception $exception) {
        throw new Google_Service_Exception('Error in Authentication. Check your client secret.');
    }

    return $client;
}

/**
 * @param $clientSecret
 * @return string
 * @throws Google_Service_Exception
 */
function getAuthCode($clientSecret) {
    $client = createClient($clientSecret);

    return json_encode(['url' => $client->createAuthUrl()]);
}

/**
 * @param $clientSecret
 * @param $authCode
 * @return string
 * @throws Google_Service_Exception
 */
function getAuthTokens($clientSecret, $authCode) {
    $client = createClient($clientSecret);

    return json_encode(['tokens' => $client->fetchAccessTokenWithAuthCode($authCode)]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $refreshToken
 * @param $summary
 * @param $description
 * @return string
 */
function createCalendar($clientSecret, $accessToken, $refreshToken, $summary, $description) {
    $success = true;
    $errors = [];
    $id = '';
    $syncToken = '';

    try {
        $client = createClient($clientSecret, $accessToken, $refreshToken);
        $service = new Google_Service_Calendar($client);
        $calendar = new Google_Service_Calendar_Calendar();
        $calendar->setSummary($summary);
        $calendar->setDescription($description);
        $createdCalendar = $service->calendars->insert($calendar);
        $params = [
            'showDeleted' => true,
            'timeMin' => Carbon::now()
                ->setTimezone($createdCalendar->getTimeZone())
                ->toAtomString()
        ];
        $events = $service->events->listEvents($createdCalendar->getId(), $params);
        $accessToken = $client->getAccessToken()['access_token'];
        $refreshToken = $client->getRefreshToken();
        $id = $createdCalendar->getId();
        $syncToken = $events->getNextSyncToken();
    } catch(Google_Service_Exception $exception) {
        $success = false;
        $errors = $exception->getErrors();
    }

    return json_encode([
        'success' => $success,
        'errors' => $errors,
        'accessToken' => $accessToken,
        'refreshToken' => $refreshToken,
        'id' => $id,
        'syncToken' => $syncToken
    ]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $refreshToken
 * @param $calendarId
 * @return string
 */
function deleteCalendar($clientSecret, $accessToken, $refreshToken, $calendarId) {
    $success = true;
    $errors = [];

    try {
        $client = createClient($clientSecret, $accessToken, $refreshToken);
        $service = new Google_Service_Calendar($client);
        $service->calendars->delete($calendarId);
        $accessToken = $client->getAccessToken()['access_token'];
        $refreshToken = $client->getRefreshToken();
    } catch(Google_Service_Exception $exception) {
        $success = false;
        $errors = $exception->getErrors();
    }

    return json_encode([
       'success' => $success,
       'errors' => $errors,
       'accessToken' => $accessToken,
       'refreshToken' => $refreshToken
    ]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $refreshToken
 * @param $calendarId
 * @param $syncToken
 * @param $localTimeZone
 * @return string
 */
function syncCalendar($clientSecret, $accessToken, $refreshToken, $calendarId, $syncToken, $localTimeZone) {
    $success = true;
    $errors = [];
    $newSyncToken = $syncToken;
    $updateEvents = [];
    $deleteEvents = [];

    try {
        $client = createClient($clientSecret, $accessToken, $refreshToken);
        $service = new Google_Service_Calendar($client);
        $accessToken = $client->getAccessToken()['access_token'];
        $refreshToken = $client->getRefreshToken();
        $calendar = $service->calendars->get($calendarId);
        $calendarTimeZone = $calendar->getTimeZone();
        $events = $service->events->listEvents($calendarId, ['syncToken' => $syncToken]);

        while(true) {

            foreach($events->getItems() as $event) {

                if($event->status != 'cancelled') {
                    $startTime = Carbon::parse($event->getStart()->getDateTime())
                        ->tz($calendarTimeZone)
                        ->setTimezone($localTimeZone)
                        ->format('Y-m-d\TH:i:s');
                    $endTime = Carbon::parse($event->getEnd()->getDateTime())
                        ->tz($calendarTimeZone)
                        ->setTimezone($localTimeZone)
                        ->format('Y-m-d\TH:i:s');
                    $updateEvents[] = [
                        'properties' => [
                            'id' => $event->getId(),
                            'summary' => $event->getSummary(),
                            'startTime' => $startTime,
                            'endTime' => $endTime,
                            'description' => $event->getDescription()
                        ],
                        'eTag' => $event->getEtag()
                    ];
                } else {
                    $deleteEvents[] = $event->getId();
                }
            }

            $pageToken = $events->getNextPageToken();

            if($pageToken) {
                $params['pageToken'] = $pageToken;
                $events = $service->events->listEvents($calendarId, $params);
            } else {
                $newSyncToken = str_replace('=ok', '', $events->getNextSyncToken());
                break;
            }
        }
    } catch(Google_Service_Exception $exception) {
        $success = false;
        $errors = $exception->getErrors();
    }

    return json_encode([
        'success' => $success,
        'errors' => $errors,
        'accessToken' => $accessToken,
        'refreshToken' => $refreshToken,
        'syncToken' => $newSyncToken,
        'updateEvents' => $updateEvents,
        'deleteEvents' => $deleteEvents
    ]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $refreshToken
 * @param $calendarId
 * @param $summary
 * @param $startTime
 * @param $endTime
 * @param string $description
 * @return string
 */
function createEvent(
    $clientSecret,
    $accessToken,
    $refreshToken,
    $calendarId,
    $summary,
    $startTime,
    $endTime,
    $description = ''
) {
    $success = true;
    $errors = [];
    $id = '';
    $eTag = '';

    try {
        $client = createClient($clientSecret, $accessToken, $refreshToken);
        $service = new Google_Service_Calendar($client);
        $event = new Google_Service_Calendar_Event();
        $event->setSummary($summary);
        $start = new Google_Service_Calendar_EventDateTime();
        $start->setDateTime($startTime);
        $event->setStart($start);
        $end = new Google_Service_Calendar_EventDateTime();
        $end->setDateTime($endTime);
        $event->setEnd($end);
        $event->setDescription($description);
        $createdEvent = $service->events->insert($calendarId, $event);
        $accessToken = $client->getAccessToken()['access_token'];
        $refreshToken = $client->getRefreshToken();
        $id = $createdEvent->getId();
        $eTag = $createdEvent->getEtag();
    } catch(Google_Service_Exception $exception) {
        $success = false;
        $errors = $exception->getErrors();
    }

    return json_encode([
        'success' => $success,
        'errors' => $errors,
        'accessToken' => $accessToken,
        'refreshToken' => $refreshToken,
        'id' => $id,
        'eTag' => $eTag
    ]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $refreshToken
 * @param $calendarId
 * @param $eventId
 * @param $eTag
 * @param $summary
 * @param $startTime
 * @param $endTime
 * @param string $description
 * @return string
 */
function updateEvent(
    $clientSecret,
    $accessToken,
    $refreshToken,
    $calendarId,
    $eventId,
    $eTag,
    $summary,
    $startTime,
    $endTime,
    $description = ''
) {
    $success = true;
    $errors = [];
    $id = '';
    $newETag = '';

    try {
        $client = createClient($clientSecret, $accessToken, $refreshToken);
        $service = new Google_Service_Calendar($client);
        $event = new Google_Service_Calendar_Event();
        $event->setSummary($summary);
        $start = new Google_Service_Calendar_EventDateTime();
        $start->setDateTime($startTime);
        $event->setStart($start);
        $end = new Google_Service_Calendar_EventDateTime();
        $end->setDateTime($endTime);
        $event->setEnd($end);
        $event->setDescription($description);
        $event->setId($eventId);
        $event->setEtag($eTag);
        $updatedEvent = $service->events->update($calendarId, $eventId, $event);
        $accessToken = $client->getAccessToken()['access_token'];
        $refreshToken = $client->getRefreshToken();
        $id = $updatedEvent->getId();
        $newETag = $updatedEvent->getEtag();
    } catch(Google_Service_Exception $exception) {
        $success = false;
        $errors = $exception->getErrors();
    }

    return json_encode([
        'success' => $success,
        'errors' => $errors,
        'accessToken' => $accessToken,
        'refreshToken' => $refreshToken,
        'id' => $id,
        'eTag' => $newETag
    ]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $refreshToken
 * @param $calendarId
 * @param $eventId
 * @return string
 */
function deleteEvent($clientSecret, $accessToken, $refreshToken, $calendarId, $eventId) {
    $success = true;
    $errors = [];

    try {
        $client = createClient($clientSecret, $accessToken, $refreshToken);
        $service = new Google_Service_Calendar($client);
        $service->events->delete($calendarId, $eventId);
        $accessToken = $client->getAccessToken()['access_token'];
        $refreshToken = $client->getRefreshToken();
    } catch(Google_Service_Exception $exception) {
        $success = false;
        $errors = $exception->getErrors();
    }

    return json_encode([
        'success' => $success,
        'errors' => $errors,
        'accessToken' => $accessToken,
        'refreshToken' => $refreshToken
    ]);
}
