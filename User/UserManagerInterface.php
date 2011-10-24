<?php

namespace KL\FeedBundle\User;

interface UserManagerInterface
{
    /**
     * 
     * 
     * @param array $ids
     * @return array
     */
    function getUsersById($ids);
}