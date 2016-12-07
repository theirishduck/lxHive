<?php

/*
 * This file is part of lxHive LRS - http://lxhive.org/
 *
 * Copyright (C) 2016 Brightcookie Pty Ltd
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with lxHive. If not, see <http://www.gnu.org/licenses/>.
 *
 * For authorship information, please view the AUTHORS
 * file that was distributed with this source code.
 */

namespace API\Storage\Adapter\MongoLegacy;

use API\Storage\Query\AgentProfileInterface;

class AgentProfile extends Base implements AgentProfileInterface
{
    public function getAgentProfilesFiltered($parameters)
    {
        $collection  = $this->getDocumentManager()->getCollection('agentProfiles');
        $cursor      = $collection->find();

        // Single activity profile
        if ($parameters->has('profileId')) {
            $cursor->where('profileId', $parameters->get('profileId'));
            $agent = $parameters->get('agent');
            $agent = json_decode($agent, true);
            //Fetch the identifier - otherwise we'd have to order the JSON
            if (isset($agent['mbox'])) {
                $uniqueIdentifier = 'mbox';
            } elseif (isset($agent['mbox_sha1sum'])) {
                $uniqueIdentifier = 'mbox_sha1sum';
            } elseif (isset($agent['openid'])) {
                $uniqueIdentifier = 'openid';
            } elseif (isset($agent['account'])) {
                $uniqueIdentifier = 'account';
            }
            $cursor->where('agent.'.$uniqueIdentifier, $agent[$uniqueIdentifier]);

            if ($cursor->count() === 0) {
                throw new Exception('Agent profile does not exist.', Resource::STATUS_NOT_FOUND);
            }

            $this->cursor   = $cursor;
            $this->single = true;

            return $this;
        }

        $agent = $parameters->get('agent');
        $agent = json_decode($agent);
        $cursor->where('agent', $agent);

        if ($parameters->has('since')) {
            $since = Util\Date::dateStringToMongoDate($parameters->get('since'));
            $cursor->whereGreaterOrEqual('mongoTimestamp', $since);
        }

        return $cursor;
    }

    public function postAgentProfile($parameters, $profileObject)
    {
        $agent = $parameters->get('agent');
        $agent = json_decode($agent, true);
        //Fetch the identifier - otherwise we'd have to order the JSON
        if (isset($agent['mbox'])) {
            $uniqueIdentifier = 'mbox';
        } elseif (isset($agent['mbox_sha1sum'])) {
            $uniqueIdentifier = 'mbox_sha1sum';
        } elseif (isset($agent['openid'])) {
            $uniqueIdentifier = 'openid';
        } elseif (isset($agent['account'])) {
            $uniqueIdentifier = 'account';
        }

        $collection  = $this->getDocumentManager()->getCollection('agentProfiles');

        // Set up the body to be saved
        $agentProfileDocument = $collection->createDocument();

        // Check for existing state - then merge if applicable
        $cursor      = $collection->find();
        $cursor->where('profileId', $parameters->get('profileId'));
        $cursor->where('agent.'.$uniqueIdentifier, $agent[$uniqueIdentifier]);

        $result = $cursor->findOne();

        // Check If-Match and If-None-Match here - these SHOULD* exist, but they do not have to
        // See https://github.com/adlnet/xAPI-Spec/blob/1.0.3/xAPI.md#lrs-requirements-7
        // if (!$parameters->get('headers')['If-Match'] && !$parameters->get('headers')['If-None-Match'] && $result) {
        //     throw new \Exception('There was a conflict. Check the current state of the resource and set the "If-Match" header with the current ETag to resolve the conflict.', Resource::STATUS_CONFLICT);
        // }

        // If-Match first
        if ($parameters->get('headers')['If-Match'] && $result && ($this->trimHeader($parameters->get('headers')['If-Match']) !== $result->getHash())) {
            throw new \Exception('If-Match header doesn\'t match the current ETag.', Resource::STATUS_PRECONDITION_FAILED);
        }

        // Then If-None-Match
        if ($parameters->get('headers')['If-None-Match']) {
            if ($this->trimHeader($parameters->get('headers')['If-None-Match']) === '*' && $result) {
                throw new \Exception('If-None-Match header is *, but a resource already exists.', Resource::STATUS_PRECONDITION_FAILED);
            } elseif ($result && $this->trimHeader($parameters->get('headers')['If-None-Match']) === $result->getHash()) {
                throw new \Exception('If-None-Match header matches the current ETag.', Resource::STATUS_PRECONDITION_FAILED);
            }
        }

        // ID exists, merge body
        $contentType = $parameters->get('headers')['Content-Type'];
        if ($contentType === null) {
            $contentType = 'text/plain';
        }

        // ID exists, try to merge body if applicable
        if ($result) {
            if ($result->getContentType() !== 'application/json') {
                throw new \Exception('Original document is not JSON. Cannot merge!', Resource::STATUS_BAD_REQUEST);
            }
            if ($contentType !== 'application/json') {
                throw new \Exception('Posted document is not JSON. Cannot merge!', Resource::STATUS_BAD_REQUEST);
            }
            $decodedExisting = json_decode($result->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON in existing document. Cannot merge!', Resource::STATUS_BAD_REQUEST);
            }

            $decodedPosted = json_decode($profileObject, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON posted. Cannot merge!', Resource::STATUS_BAD_REQUEST);
            }

            $profileObject = json_encode(array_merge($decodedExisting, $decodedPosted));
            $agentProfileDocument = $result;
        }

        $agentProfileDocument->setContent($profileObject);
        // Dates
        $currentDate = Util\Date::dateTimeExact();
        $agentProfileDocument->setMongoTimestamp(Util\Date::dateTimeToMongoDate($currentDate));
        $agentProfileDocument->setAgent($agent);
        $agentProfileDocument->setProfileId($parameters->get('profileId'));
        $agentProfileDocument->setContentType($contentType);
        $agentProfileDocument->setHash(sha1($profileObject));
        $agentProfileDocument->save();

        // Add to log
        $this->getSlim()->requestLog->addRelation('agentProfiles', $agentProfileDocument)->save();

        return $agentProfileDocument;
    }

    public function putAgentProfile($parameters, $profileObject)
    {
        $agent = $parameters->get('agent');
        $agent = json_decode($agent, true);
        //Fetch the identifier - otherwise we'd have to order the JSON
        if (isset($agent['mbox'])) {
            $uniqueIdentifier = 'mbox';
        } elseif (isset($agent['mbox_sha1sum'])) {
            $uniqueIdentifier = 'mbox_sha1sum';
        } elseif (isset($agent['openid'])) {
            $uniqueIdentifier = 'openid';
        } elseif (isset($agent['account'])) {
            $uniqueIdentifier = 'account';
        }

        $collection  = $this->getDocumentManager()->getCollection('agentProfiles');

        $agentProfileDocument = $collection->createDocument();

        // Check for existing state - then replace if applicable
        $cursor      = $collection->find();
        $cursor->where('profileId', $parameters->get('profileId'));
        $cursor->where('agent.'.$uniqueIdentifier, $agent[$uniqueIdentifier]);

        $result = $cursor->findOne();

        // Check If-Match and If-None-Match here
        if (!$parameters->get('headers')['If-Match'] && !$parameters->get('headers')['If-Match'] && $result) {
            throw new \Exception('There was a conflict. Check the current state of the resource and set the "If-Match" header with the current ETag to resolve the conflict.', Resource::STATUS_CONFLICT);
        }

        // If-Match first
        if ($parameters->get('headers')['If-Match'] && $result && ($this->trimHeader($parameters->get('headers')['If-Match']) !== $result->getHash())) {
            throw new \Exception('If-Match header doesn\'t match the current ETag.', Resource::STATUS_PRECONDITION_FAILED);
        }

        // Then If-None-Match
        if ($parameters->get('headers')['If-None-Match']) {
            if ($this->trimHeader($parameters->get('headers')['If-None-Match']) === '*' && $result) {
                throw new \Exception('If-None-Match header is *, but a resource already exists.', Resource::STATUS_PRECONDITION_FAILED);
            } elseif ($result && $this->trimHeader($parameters->get('headers')['If-None-Match']) === $result->getHash()) {
                throw new \Exception('If-None-Match header matches the current ETag.', Resource::STATUS_PRECONDITION_FAILED);
            }
        }

        // ID exists, replace body
        if ($result) {
            $agentProfileDocument = $result;
        }

        $contentType = $parameters->get('headers')['Content-Type'];
        if ($contentType === null) {
            $contentType = 'text/plain';
        }

        $agentProfileDocument->setContent($profileObject);
        // Dates
        $currentDate = Util\Date::dateTimeExact();
        $agentProfileDocument->setMongoTimestamp(Util\Date::dateTimeToMongoDate($currentDate));

        $agentProfileDocument->setAgent($agent);
        $agentProfileDocument->setProfileId($parameters->get('profileId'));
        $agentProfileDocument->setContentType($contentType);
        $agentProfileDocument->setHash(sha1($profileObject));
        $agentProfileDocument->save();

        // Add to log
        $this->getSlim()->requestLog->addRelation('agentProfiles', $agentProfileDocument)->save();

        return $agentProfileDocument;
    }

    public function deleteAgentProfile($parameters)
    {
        $collection  = $this->getDocumentManager()->getCollection('agentProfiles');
        $cursor      = $collection->find();

        $cursor->where('profileId', $parameters->get('profileId'));
        $agent = $parameters->get('agent');
        $agent = json_decode($agent, true);
        //Fetch the identifier - otherwise we'd have to order the JSON
        if (isset($agent['mbox'])) {
            $uniqueIdentifier = 'mbox';
        } elseif (isset($agent['mbox_sha1sum'])) {
            $uniqueIdentifier = 'mbox_sha1sum';
        } elseif (isset($agent['openid'])) {
            $uniqueIdentifier = 'openid';
        } elseif (isset($agent['account'])) {
            $uniqueIdentifier = 'account';
        }
        $cursor->where('agent.'.$uniqueIdentifier, $agent[$uniqueIdentifier]);

        $result = $cursor->findOne();

        if (!$result) {
            throw new \Exception('Profile does not exist!.', Resource::STATUS_NOT_FOUND);
        }

        // Check If-Match and If-None-Match here - these SHOULD* exist, but they do not have to
        // See https://github.com/adlnet/xAPI-Spec/blob/1.0.3/xAPI.md#lrs-requirements-7
        // if (!$parameters->get('headers')['If-Match'] && !$parameters->get('headers')['If-None-Match'] && $result) {
        //     throw new \Exception('There was a conflict. Check the current state of the resource and set the "If-Match" header with the current ETag to resolve the conflict.', Resource::STATUS_CONFLICT);
        // }

        // If-Match first
        if ($parameters->get('headers')['If-Match'] && $result && ($this->trimHeader($parameters->get('headers')['If-Match']) !== $result->getHash())) {
            throw new \Exception('If-Match header doesn\'t match the current ETag.', Resource::STATUS_PRECONDITION_FAILED);
        }

        // Then If-None-Match
        if ($parameters->get('headers')['If-None-Match']) {
            if ($this->trimHeader($parameters->get('headers')['If-None-Match']) === '*' && $result) {
                throw new \Exception('If-None-Match header is *, but a resource already exists.', Resource::STATUS_PRECONDITION_FAILED);
            } elseif ($result && $this->trimHeader($parameters->get('headers')['If-None-Match']) === $result->getHash()) {
                throw new \Exception('If-None-Match header matches the current ETag.', Resource::STATUS_PRECONDITION_FAILED);
            }
        }

        // Add to log
        $this->getSlim()->requestLog->addRelation('agentProfiles', $result)->save();

        $result->delete();
    }

    // REMOVE THIS URGENTLY!
     /**
     * Trims quotes from the header.
     *
     * @param string $headerString Header
     *
     * @return string Trimmed header
     */
    private function trimHeader($headerString)
    {
        return trim($headerString, '"');
    }
}