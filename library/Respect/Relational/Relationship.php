<?php

namespace Respect\Relational;

class Relationship
{

    protected $from;
    protected $to;
    protected $keys = array();

    public function __construct($from=null, $to=null, array $keys=array())
    {
        $this->from = $from;
        $this->to = $to;
        $this->keys = $keys;
    }

    public function asInnerJoin($includeFrom=false, $aliasFrom=null, $aliasTo=null)
    {
        $sql = new Sql();
        if ($includeFrom)
            $sql->from($this->from);

        if ($aliasFrom)
            $sql->as($aliasFrom);

        $sql->innerJoin($this->to);

        if ($aliasTo)
            $sql->as($aliasTo);

        return $sql->on($this->createJoinKeys($aliasFrom, $aliasTo));
    }

    protected function createJoinKeys($aliasFrom=null, $aliasTo=null)
    {
        $keys = array();

        foreach ($this->keys as $kFrom => $kTo)
            $keys[($aliasFrom? : $this->from) . ".$kFrom"]
                = ($aliasTo? : $this->to) . ".$kTo";

        return $keys;
    }

}

/**
 * LICENSE
 *
 * Copyright (c) 2009-2011, Alexandre Gomes Gaigalas.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 *     * Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *     * Redistributions in binary form must reproduce the above copyright notice,
 *       this list of conditions and the following disclaimer in the documentation
 *       and/or other materials provided with the distribution.
 *
 *     * Neither the name of Alexandre Gomes Gaigalas nor the names of its
 *       contributors may be used to endorse or promote products derived from this
 *       software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 */