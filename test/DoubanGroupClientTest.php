<?php
require_once dirname(__DIR__) . "/src/DoubanGroupClient.php";
/**
 * Created by PhpStorm.
 * User: mengjun
 * Date: 15/9/14
 * Time: 下午1:53
 */
class DoubanGroupClientTest extends PHPUnit_Framework_TestCase {

	public function testLogin() {
		$client = new DoubanGroupClient("xieba9663491339@163.com", "7l250f4yuy");
		$client->login();
	}
}