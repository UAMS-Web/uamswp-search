<?php
use PHPUnit\Framework\TestCase;
use UAMS_Search\Related;
final class RelatedTest extends TestCase {
    public function testRelationshipIdsAcceptOnlyPositiveFlatIntegerIds(): void {
        self::assertSame([12,13],Related::ids(['12',12,13,0,-1,false,true,null,'12.3','1e2','Barnes',(object)['ID'=>14],[15]]));
        self::assertSame([],Related::ids(null));
        self::assertSame([],Related::ids(new stdClass()));
        self::assertSame([12],Related::ids('12'));
    }
}
