<?php

namespace ChatStats\Messages;

interface MessageSource
{
    public function getMessages(): MessageCollection;
}