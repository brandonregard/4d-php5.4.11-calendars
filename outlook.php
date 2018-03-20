<?php
/**
 * File: outlook.php
 * Project: PrintPoint
 * User: brandon
 * Date: 3/9/18
 */

require_once __DIR__ . '/vendor/autoload.php';

use League\OAuth2\Client\Provider\GenericProvider;
use Microsoft\Graph\Graph;

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $accessTokenExpires
 * @param $refreshToken
 * @return array
 * @throws Exception
 */
function refreshAuthTokens($clientSecret, $accessToken, $accessTokenExpires, $refreshToken) {

    try {
        $client = new GenericProvider(json_decode($clientSecret, true));

        if(intval($accessTokenExpires) <= (time() + 300)) {
            $accessToken = $client->getAccessToken('refresh_token', ['refresh_token' => $refreshToken]);
            $refreshToken = $accessToken->getRefreshToken();
            $accessTokenExpires = $accessToken->getExpires();
            $accessToken = $accessToken->getToken();
        }
    } catch(Exception $exception) {
        throw new Exception('Error in Authentication. Check your client secret.');
    }

    return [
        'accessToken' => $accessToken,
        'accessTokenExpires' => $accessTokenExpires,
        'refreshToken' => $refreshToken
    ];
}

/**
 * @param $clientSecret
 * @return string
 */
function getAuthCode($clientSecret) {
    $provider = new GenericProvider(json_decode($clientSecret, true));

    return json_encode(['url' => $provider->getAuthorizationUrl()]);
}

/**
 * @param $clientSecret
 * @param $authCode
 * @return string
 */
function getAuthTokens($clientSecret, $authCode) {
    $provider = new GenericProvider(json_decode($clientSecret, true));
    $accessToken = $provider->getAccessToken('authorization_code', ['code' => $authCode]);

    return json_encode([
        'accessToken' => $accessToken->getToken(),
        'accessTokenExpires' => $accessToken->getExpires(),
        'refreshToken' => $accessToken->getRefreshToken()
    ]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $accessTokenExpires
 * @param $refreshToken
 * @param $name
 * @return string
 */
function createCalendar($clientSecret, $accessToken, $accessTokenExpires, $refreshToken, $name) {
    $success = true;
    $error = '';
    $id = '';
    $syncToken = '';

    try {
        $tokens = refreshAuthTokens($clientSecret, $accessToken, $accessTokenExpires, $refreshToken);
        $graph = new Graph();
        $graph->setAccessToken($tokens['accessToken']);
        $calendar = $graph->createRequest('POST', '/users/me/calendars')
            ->attachBody(json_encode(['name' => $name]))
            ->setReturnType('Microsoft\Graph\Model\Calendar')
            ->execute();
        $id = $calendar->getProperties()['id'];
        $url = '/users/me/calendars/'
            . $id . '/calendarview/delta?startdatetime=2000-01-01T00:00:00Z&enddatetime=2099-12-31T23:59:59Z';
        $response = $graph->createCollectionRequest('GET', $url)->execute();

        while(true) {
            $nextLink = $response->getNextLink();

            if($nextLink !== null) {
                $response = $graph->createCollectionRequest('GET', $nextLink)->execute();
            }

            $deltaLink = $response->getDeltaLink();

            if($deltaLink !== null) {
                print_r($deltaLink);
                $params = parse_url($deltaLink, PHP_URL_QUERY);
                $syncToken = explode('=', $params)[1];
                break;
            }
        }
        $accessToken = $tokens['accessToken'];
        $accessTokenExpires = $tokens['accessTokenExpires'];
        $refreshToken = $tokens['refreshToken'];
    } catch (Exception $exception) {
        $success = false;
        $error = $exception->getMessage();
    }

    return json_encode([
       'success' => $success,
       'error' => $error,
       'accessToken' => $accessToken,
       'accessTokenExpires' => $accessTokenExpires,
       'refreshToken' => $refreshToken,
       'id' => $id,
       'syncToken' => $syncToken
    ]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $accessTokenExpires
 * @param $refreshToken
 * @param $id
 * @return string
 */
function deleteCalendar($clientSecret, $accessToken, $accessTokenExpires, $refreshToken, $id) {
    $success = true;
    $error = '';

    try {
        $tokens = refreshAuthTokens($clientSecret, $accessToken, $accessTokenExpires, $refreshToken);
        $graph = new Graph();
        $graph->setAccessToken($tokens['accessToken']);
        $graph->createRequest('PATCH', '/users/me/calendars/' . $id)
            ->attachBody(json_encode(['name' => uniqid()]))
            ->execute();
        $graph->createRequest('DELETE', '/users/me/calendars/' . $id)->execute();
        $accessToken = $tokens['accessToken'];
        $accessTokenExpires = $tokens['accessTokenExpires'];
        $refreshToken = $tokens['refreshToken'];
    } catch (Exception $exception) {
        $success = false;
        $error = $exception->getMessage();
    }

    return json_encode([
        'success' => $success,
        'error' => $error,
        'accessToken' => $accessToken,
        'accessTokenExpires' => $accessTokenExpires,
        'refreshToken' => $refreshToken,
    ]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $accessTokenExpires
 * @param $refreshToken
 * @param $calendarId
 * @param $syncToken
 * @return string
 */
function syncCalendar($clientSecret, $accessToken, $accessTokenExpires, $refreshToken, $calendarId, $syncToken) {
    $success = true;
    $error = '';
    $newSyncToken = $syncToken;
    $updateEvents = [];
    $deleteEvents = [];

    try {
        $tokens = refreshAuthTokens($clientSecret, $accessToken, $accessTokenExpires, $refreshToken);
        $graph = new Graph();
        $graph->setAccessToken($tokens['accessToken']);
        $url = '/users/me/calendars/' . $calendarId . '/calendarview/delta?$deltatoken=' . $syncToken;

        while(true) {
            $response = $graph->createCollectionRequest('GET', $url)
                ->addHeaders(['Prefer' => "outlook.body-content-type='text'"])
                ->execute();

            foreach($response->getResponseAsObject('Microsoft\Graph\Model\Event') as $event) {

                if(!array_key_exists('@removed', $event->getProperties())) {
                    $updateEvents[] = [
                        'properties' => [
                            'id' => $event->getId(),
                            'subject' => $event->getSubject(),
                            'startTime' => $event->getStart()->getDateTime(),
                            'endTime' => $event->getEnd()->getDateTime(),
                            'description' => $event->getBody()->getContent()
                        ],
                        'changeKey' => $event->getChangeKey()
                    ];
                } else {
                    $deleteEvents[] = $event->getId();
                }
            }

            $nextLink = $response->getNextLink();

            if($nextLink !== null) {
                $url = $nextLink;
            } else {
                $deltaLink = $response->getDeltaLink();

                if ($deltaLink !== null) {
                    $params = parse_url($deltaLink, PHP_URL_QUERY);
                    $newSyncToken = explode('=', $params)[1];
                    break;
                }
            }
        }
    } catch (Exception $exception) {
        $success = false;
        $error = $exception->getMessage();
    }

    return json_encode([
        'success' => $success,
        'error' => $error,
        'accessToken' => $accessToken,
        'accessTokenExpires' => $accessTokenExpires,
        'refreshToken' => $refreshToken,
        'syncToken' => $newSyncToken,
        'updateEvents' => $updateEvents,
        'deleteEvents' => $deleteEvents
    ]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $accessTokenExpires
 * @param $refreshToken
 * @param $calendarId
 * @param $subject
 * @param $startTime
 * @param $endTime
 * @param string $description
 * @return string
 */
function createEvent(
    $clientSecret,
    $accessToken,
    $accessTokenExpires,
    $refreshToken,
    $calendarId,
    $subject,
    $startTime,
    $endTime,
    $description = ''
) {
    $success = true;
    $error = '';
    $id = '';
    $changeKey = '';

    try {
        $tokens = refreshAuthTokens($clientSecret, $accessToken, $accessTokenExpires, $refreshToken);
        $graph = new Graph();
        $graph->setAccessToken($tokens['accessToken']);
        $body = new \Microsoft\Graph\Model\ItemBody(['contentType' => 'Text', 'content' => $description]);
        $startTime = new \Microsoft\Graph\Model\DateTimeTimeZone(['dateTime' => $startTime, 'timeZone' => 'UTC']);
        $endTime = new \Microsoft\Graph\Model\DateTimeTimeZone(['dateTime' => $endTime, 'timeZone' => 'UTC']);
        $event = $graph->createRequest('POST', '/users/me/calendars/' . $calendarId . '/events')
            ->attachBody([
                'subject' => $subject,
                'start' => $startTime,
                'end' => $endTime,
                'body' => $body
            ])
            ->setReturnType('Microsoft\Graph\Model\Event')
            ->execute();
        $accessToken = $tokens['accessToken'];
        $accessTokenExpires = $tokens['accessTokenExpires'];
        $refreshToken = $tokens['refreshToken'];
        $id = $event->getId();
        $changeKey = $event->getChangeKey();
    } catch(Exception $exception) {
        $success = false;
        $error = $exception->getMessage();
    }

    return json_encode([
        'success' => $success,
        'error' => $error,
        'accessToken' => $accessToken,
        'accessTokenExpires' => $accessTokenExpires,
        'refreshToken' => $refreshToken,
        'id' => $id,
        'changeKey' => $changeKey
    ]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $accessTokenExpires
 * @param $refreshToken
 * @param $calendarId
 * @param $eventId
 * @param $changeKey
 * @param $subject
 * @param $startTime
 * @param $endTime
 * @param string $description
 * @return string
 */
function updateEvent(
    $clientSecret,
    $accessToken,
    $accessTokenExpires,
    $refreshToken,
    $calendarId,
    $eventId,
    $changeKey,
    $subject,
    $startTime,
    $endTime,
    $description = ''
) {
    $success = true;
    $error = '';
    $id = '';
    $newChangeKey = '';

    try {
        $tokens = refreshAuthTokens($clientSecret, $accessToken, $accessTokenExpires, $refreshToken);
        $graph = new Graph();
        $graph->setAccessToken($tokens['accessToken']);
        $body = new \Microsoft\Graph\Model\ItemBody(['contentType' => 'Text', 'content' => $description]);
        $startTime = new \Microsoft\Graph\Model\DateTimeTimeZone(['dateTime' => $startTime, 'timeZone' => 'UTC']);
        $endTime = new \Microsoft\Graph\Model\DateTimeTimeZone(['dateTime' => $endTime, 'timeZone' => 'UTC']);
        $event = $graph->createRequest(
            'PATCH',
            '/users/me/calendars/' . $calendarId . '/events/' . $eventId)
            ->attachBody([
                'subject' => $subject,
                'start' => $startTime,
                'end' => $endTime,
                'body' => $body,
                'changeKey' => $changeKey
            ])
            ->setReturnType('Microsoft\Graph\Model\Event')
            ->execute();
        $accessToken = $tokens['accessToken'];
        $accessTokenExpires = $tokens['accessTokenExpires'];
        $refreshToken = $tokens['refreshToken'];
        $id = $event->getId();
        $newChangeKey = $event->getChangeKey();
    } catch(Exception $exception) {
        $success = false;
        $error = $exception->getMessage();
    }

    return json_encode([
        'success' => $success,
        'error' => $error,
        'accessToken' => $accessToken,
        'accessTokenExpires' => $accessTokenExpires,
        'refreshToken' => $refreshToken,
        'id' => $id,
        'changeKey' => $newChangeKey
    ]);
}

/**
 * @param $clientSecret
 * @param $accessToken
 * @param $accessTokenExpires
 * @param $refreshToken
 * @param $calendarId
 * @param $eventId
 * @return string
 */
function deleteEvent($clientSecret, $accessToken, $accessTokenExpires, $refreshToken, $calendarId, $eventId) {
    $success = true;
    $error = '';

    try {
        $tokens = refreshAuthTokens($clientSecret, $accessToken, $accessTokenExpires, $refreshToken);
        $graph = new Graph();
        $graph->setAccessToken($tokens['accessToken']);
        $graph->createRequest(
            'DELETE', '/users/me/calendars/' . $calendarId . '/events/' . $eventId)
            ->execute();
        $accessToken = $tokens['accessToken'];
        $accessTokenExpires = $tokens['accessTokenExpires'];
        $refreshToken = $tokens['refreshToken'];
    } catch(Exception $exception) {
        $success = false;
        $error = $exception->getMessage();
    }

    return json_encode([
        'success' => $success,
        'error' => $error,
        'accessToken' => $accessToken,
        'accessTokenExpires' => $accessTokenExpires,
        'refreshToken' => $refreshToken
    ]);
}