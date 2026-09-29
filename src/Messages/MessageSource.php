<?php

namespace ChatStats\Messages;

interface MessageSource
{
    /**
     * @return iterable<Message>|MessageCollection источник сообщений
     */
    public function getMessages();
}