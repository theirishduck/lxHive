<?php

/*
 * This file is part of lxHive LRS - http://lxhive.org/
 *
 * Copyright (C) 2017 Brightcookie Pty Ltd
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

namespace API\Storage\Adapter\Mongo;

use API\Storage\SchemaInterface;
use API\Storage\Query\UserInterface;

use API\Storage\Provider;
use API\Controller;
use API\HttpException as Exception;

class User extends Provider implements UserInterface, SchemaInterface
{
    const COLLECTION_NAME = 'users';

    /**
     * @inherit
     */
    public function install()
    {
    }

    public function findByEmailAndPassword($username, $password)
    {
        $storage = $this->getContainer()['storage'];
        $expression = $storage->createExpression();

        $expression->where('email', $params->get('email'));
        $expression->where('passwordHash', sha1($params->get('password')));

        $document = $storage->findOne(self::COLLECTION_NAME, $expression);

        return $document;
    }

    public function findById($id)
    {
        $storage = $this->getContainer()['storage'];
        $expression = $storage->createExpression();

        $expression->where('_id', $id);

        $result = $storage->findOne($id);

        return $result;
    }

    /**
     * Add a user
     * The only validation we do at this level is ensuring that the email is unique
     *
     * @param string $name
     * @param string $description
     * @param string $email valid email address
     * @param string $password
     * @param array  $permissions valid array of permission records
     *
     * @throws Exception
     */
    public function addUser($name, $description, $email, $password, $permissions)
    {
        $storage = $this->getContainer()['storage'];

        // check if email is valid and unique
        if ($this->hasEmail($email)) {
            throw new Exception('User email exists already.', Controller::STATUS_BAD_REQUEST);
        }

        // Set up the User to be saved
        $userDocument = new \API\Document\Generic();

        $userDocument->setName($name);
        $userDocument->setDescription($description);
        $userDocument->setEmail($email);

        $passwordHash = sha1($password);
        $userDocument->setPasswordHash($passwordHash);

        // Permission is of type Scope
        $permissionIds = [];
        foreach ($permissions as $permission) {
            // Fetch the permission ID's and assign them
            $permissionIds[] = $permission->_id;
            $userDocument->setPermissionIds($permissionIds);
        }

        $now = new \DateTime();
        $userDocument->setCreatedAt(\API\Util\Date::dateTimeToMongoDate($now));

        $storage->insertOne(self::COLLECTION_NAME, $userDocument);

        return $userDocument;
    }

    public function fetchAll()
    {
        $storage = $this->getContainer()['storage'];
        $cursor = $storage->find(self::COLLECTION_NAME);

        $documentResult = new \API\Storage\Query\DocumentResult();
        $documentResult->setCursor($cursor);

        return $documentResult;
    }

    public function fetchAvailablePermissions()
    {
        $storage = $this->getContainer()['storage'];

        $cursor = $storage->find(AuthScopes::COLLECTION_NAME);

        $documentResult = new \API\Storage\Query\DocumentResult();
        $documentResult->setCursor($cursor);

        return $documentResult;
    }

    // TODO remove after indexing user.email as unique
    public function hasEmail($email)
    {
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $storage = $this->getContainer()['storage'];
        $count = $storage->count(self::COLLECTION_NAME, [
            'email' => $email,
        ]);

        return ($count > 0);
    }
}