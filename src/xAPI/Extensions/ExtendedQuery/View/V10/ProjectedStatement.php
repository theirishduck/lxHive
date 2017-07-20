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

namespace API\Extensions\ExtendedQuery\View\V10;

use API\View;

/**
 * Statement view
 * @see \API\View
 */
class ProjectedStatement extends View
{
    /**
     * Render response view
     * @param  \API\Storage\Query\StatementInterface $statementResult
     * @return array hashmap of view properites, ready to be serialized into json
     */
    public function render($statementResult)
    {
        $view = [];
        $idArray = [];
        $resultArray = [];

        $view['statements'] = [];
        $view['more'] = '';
        $view['totalCount'] = $statementResult->getTotalCount();

        foreach ($statementResult->getCursor() as $result) {
            unset($result->_id);
            if (isset($result['statement'])) {
                $result = $result['statement'];
                $idArray[] = $result['id'];
                $resultArray[] = $result;
            }
        }

        // TODO: Abstract this away somewhere...
        if ($statementResult->getHasMore()) {
            $latestId = end($idArray);
            if ($statementResult->getSortDescending()) {
                $this->getContainer()['url']->getQuery()->modify(['until_id' => $latestId]);
            } else { //Ascending
                $this->getContainer()['url']->getQuery()->modify(['since_id' => $latestId]);
            }
            $view['more'] = $this->getContainer()['url']->getRelativeUrl();
        }

        $view['statements'] = array_values($resultArray);

        return $view;
    }
}