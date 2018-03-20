<?php

/**
 * File: ical.php
 * Project: PrintPoint
 * User: brandon
 * Date: 3/8/18
 */

require __DIR__ . '/vendor/autoload.php';

use CalDAVClient\Facade\CalDavClient;
use CalDAVClient\Facade\Requests\MakeCalendarRequestVO;
use CalDAVClient\Facade\Requests\EventRequestVO;

/**
 * @param $user
 * @param $pass
 * @param $name
 * @param $description
 * @return string
 */
function createCalendar($user, $pass, $name, $description)
{
    $success = false;
    $calendar = [];
    $client = new CalDavClient('https://caldav.icloud.com', $user, $pass);

    if ($client->isValidServer()) {
        $principalUrl = $client->getUserPrincipal()->getPrincipalUrl();
        $calendarHomeSetUrl = $client->getCalendarHome($principalUrl)->getCalendarHomeSetUrl();
        $calendar = new MakeCalendarRequestVO($name, $name, $description);
        $response = $client->createCalendar($calendarHomeSetUrl, $calendar);

        if ($response) {
            $url = $response;
            $response = $client->getCalendar($url);
            $success = $response->isSuccessFull();

            if ($success) {

                $calendar = [
                    'syncToken' => $response->getSyncToken(),
                    'url' => $url
                ];
            }
        }
    }

    return json_encode([
        'success' => $success,
        'calendar' => $calendar
    ]);
}

/**
 * @param $user
 * @param $pass
 * @param $calendarUrl
 * @return string
 */
function deleteCalendar($user, $pass, $calendarUrl) {
    $success = false;
    $client = new CalDavClient('https://caldav.icloud.com', $user, $pass);

    if ($client->isValidServer()) {
        $response = $client->deleteCalendar($calendarUrl);
        $success = $response->isSuccessFull();
    }

    return json_encode([
       'success' => $success
    ]);
}

/**
 * @param $user
 * @param $pass
 * @param $calendarUrl
 * @param $syncToken
 * @return string
 */
function syncCalendar($user, $pass, $calendarUrl, $syncToken)
{
    $success = false;
    $token = '';
    $updateEvents = [];
    $deleteEvents = [];
    $client = new CalDavClient('https://caldav.icloud.com', $user, $pass);

    if ($client->isValidServer()) {
        $syncInfo = $client->getCalendarSyncInfo($calendarUrl, $syncToken);
        $success = $syncInfo->isSuccessFull();

        if($success) {
            $token = $syncInfo->getSyncToken();

            if ($syncInfo->hasAvailableChanges()) {
                $updateUrls = array_map(function ($event) {
                    return $event->getHRef();
                }, $syncInfo->getUpdates());
                $updateEvents = $client->getEventsBy($calendarUrl, $updateUrls);
                $success = $updateEvents->isSuccessFull();

                if($success) {
                    $updateEvents = array_map(function ($event) {

                        if ($event->getVCard()) {
                            $eTag = $event->getETag();
                            $url = $event->getHRef();
                            $event = explode("\n", $event->getVCard());
                            $properties = [];

                            foreach ($event as $property) {
                                $pair = explode(":", $property);

                                if (count($pair) > 1) {
                                    $properties[$pair[0]] = $pair[1];
                                }
                            }
                            return [
                                'properties' => $properties,
                                'resourceUrl' => $url,
                                'eTag' => $eTag
                            ];
                        } else {
                            return null;
                        }
                    }, $updateEvents->getResponses());
                    $updateEvents = array_values(array_filter($updateEvents, function ($event) {
                        return $event !== null;
                    }));
                }
                $deleteEvents = array_map(function ($event) {
                    return $event->getHRef();
                }, $syncInfo->getDeletes());
            }
        }
    }

    return json_encode([
        'success' => $success,
        'syncToken' => $token,
        'updateEvents' => $updateEvents,
        'deleteEvents' => $deleteEvents
    ]);
}

/**
 * @param $user
 * @param $pass
 * @param $calendarUrl
 * @param $eventUrl
 * @return string
 */
function getEventETag($user, $pass, $calendarUrl, $eventUrl) {
    $success = false;
    $eTag = '';
    $client = new CalDavClient('https://caldav.icloud.com', $user, $pass);

    if ($client->isValidServer()) {
        $response = $client->getEventsBy($calendarUrl, [$eventUrl]);
        $success = $response->isSuccessFull();

        if($success) {
            $events = $response->getResponses();
            $event = reset($events);

            if ($event->getVCard()) {
                $success = true;
                $eTag = $event->getETag();
            } else {
                $success = false;
            }
        }
    }

    return json_encode([
        'success' => $success,
        'eTag' => $eTag
    ]);
}

/**
 * @param $user
 * @param $pass
 * @param $calendarUrl
 * @param $title
 * @param $startTime
 * @param $endTime
 * @param string $timeZone
 * @param string $description
 * @param string $summary
 * @param string $locationName
 * @param string $locationTitle
 * @param string $locationLat
 * @param string $locationLong
 * @param string $prodId
 * @return string
 */
function createEvent(
    $user,
    $pass,
    $calendarUrl,
    $title,
    $startTime,
    $endTime,
    $timeZone = 'UTC',
    $description = '',
    $summary = '',
    $locationName = '',
    $locationTitle = '',
    $locationLat = '',
    $locationLong = '',
    $prodId = 'PRINTPOINT7000' // this should get registered (https://tools.ietf.org/html/rfc5545#section-3.3.7)
)
{
    $success = false;
    $client = new CalDavClient('https://caldav.icloud.com', $user, $pass);
    $startTime = new DateTime($startTime);
    $endTime = new DateTime($endTime);
    $timeZone = new DateTimeZone($timeZone);

    $event = new EventRequestVO(
        $prodId,
        $title,
        $description,
        $summary,
        $startTime,
        $endTime,
        $timeZone,
        $locationName,
        $locationTitle,
        $locationLat,
        $locationLong
    );

    if ($client->isValidServer()) {
        $response = $client->createEvent($calendarUrl, $event);
        $success = $response->isSuccessFull();

        if ($success) {
            $event = [
                'uid' => $response->getuid(),
                'resourceUrl' => $response->getResourceUrl(),
                'eTag' => $response->getETag()
            ];
        }
    }

    return json_encode([
        'success' => $success,
        'event' => $event
    ]);
}

/**
 * @param $user
 * @param $pass
 * @param $calendarUrl
 * @param $uid
 * @param $eTag
 * @param $title
 * @param $startTime
 * @param $endTime
 * @param string $timeZone
 * @param string $description
 * @param string $summary
 * @param string $locationName
 * @param string $locationTitle
 * @param string $locationLat
 * @param string $locationLong
 * @param string $prodId
 * @return string
 */
function updateEvent(
    $user,
    $pass,
    $calendarUrl,
    $uid,
    $eTag,
    $title,
    $startTime,
    $endTime,
    $timeZone = 'UTC',
    $description = '',
    $summary = '',
    $locationName = '',
    $locationTitle = '',
    $locationLat = '',
    $locationLong = '',
    $prodId = 'PRINTPOINT7000' // this should get registered (https://tools.ietf.org/html/rfc5545#section-3.3.7)
)
{
    $success = false;
    $client = new CalDavClient('https://caldav.icloud.com', $user, $pass);
    $startTime = new DateTime($startTime);
    $endTime = new DateTime($endTime);
    $timeZone = new DateTimeZone($timeZone);

    $event = new EventRequestVO(
        $prodId,
        $title,
        $description,
        $summary,
        $startTime,
        $endTime,
        $timeZone,
        $locationName,
        $locationTitle,
        $locationLat,
        $locationLong
    );
    $event->setUID($uid);

    if ($client->isValidServer()) {
        $response = $client->updateEvent($calendarUrl, $event, $eTag);
        $success = $response->isSuccessFull();

        if ($success) {
            $event = [
                'uid' => $response->getuid(),
                'resourceUrl' => $response->getResourceUrl(),
                'eTag' => $response->getETag()
            ];
        }

    }

    return json_encode([
        'success' => $success,
        'event' => $event
    ]);
}

/**
 * @param $user
 * @param $pass
 * @param $calendarUrl
 * @param $uid
 * @return string
 */
function deleteEvent($user, $pass, $calendarUrl, $uid)
{
    $success = false;
    $client = new CalDavClient('https://caldav.icloud.com', $user, $pass);

    if ($client->isValidServer()) {
        $response = $client->deleteEvent($calendarUrl, $uid, '');
        $success = $response->isSuccessFull();
    }

    return json_encode([
        'success' => $success
    ]);
}
