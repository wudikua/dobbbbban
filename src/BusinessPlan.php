<?php
require_once "DoubanGroupClient.php";
/**
 * Created by PhpStorm.
 * User: mengjun
 * Date: 15/9/15
 * Time: 下午6:11
 */
class BusinessPlan {

	public static $redis;

	/**
	 * @return Redis
	 */
	public static function getRedis() {
		if (self::$redis == null) {
			self::$redis = new Redis();
			self::$redis->connect("localhost");
		}
		return self::$redis;
	}

	/**
	 * @param $client DoubanGroupClient
	 */
	public static function pubUgg($client, $groupId) {
		$redis = self::getRedis();
		$postId = $redis->hGet("user:{$client->userId}:ugg", $groupId);
		if ($postId > 0) {
			// 已经发过帖子
			return;
		}
		$content = file_get_contents("ugg/content");
		$result = $client->publish($groupId, "UGG原厂招兼职代理啊", $content, [
			__DIR__.DIRECTORY_SEPARATOR."douban.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban1.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban2.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban3.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban4.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban5.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban6.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban7.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban8.jpg",
		]);
		if ($result == false) {
			return false;
		}
		$redis->hSet("user:{$client->userId}:ugg", $groupId, $result['topic']['id']);
		sleep(60);
	}

	/**
	 * @param $client DoubanGroupClient
	 */
	public static function pubHello($client, $groupId) {
		$redis = self::getRedis();
		$postId = $redis->hGet("user:{$client->userId}:hello", $groupId);
		if ($postId > 0) {
			// 已经发过帖子
			return;
		}
		$content = file_get_contents("hello/content");
		$result = $client->publish($groupId, "香港5折包包招兼职代理啊", $content, [
			__DIR__.DIRECTORY_SEPARATOR."douban.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban1.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban2.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban3.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban4.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban5.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban6.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban7.jpg",
			__DIR__.DIRECTORY_SEPARATOR."ugg/douban8.jpg",
		]);
		if ($result == false) {
			return;
		}
		$redis->hSet("user:{$client->userId}:ugg", $groupId, $result['topic']['id']);
		sleep(60);
	}

	/**
	 * @param $client DoubanGroupClient
	 */
	public static function join($client, $groupId) {
		$redis = self::getRedis();
		$hasJoin = $redis->hGet("user:{$client->userId}:join", $groupId);
		if ($hasJoin > 0) {
			return;
		}
		$result = $client->joinGroup($groupId);
		if ($result == false) {
			return;
		}
		$redis->hSet("user:{$client->userId}:join", $groupId, 1);
		sleep(10);
	}

	/**
	 * @param $client DoubanGroupClient
	 */
	public static function searchJoin($client, $keywords) {
		$result = $client->searchGroup($keywords);
		uasort($result['groups'], function($a, $b) {
			if (intval($a['member_count']) == intval($b['member_count']))
				return 0;
			return (intval($a['member_count']) > intval($b['member_count'])) ? -1 : 1;
		});
		$top = 1;
		$i =0;
		array_walk($result['groups'], function($group) use ($client, &$i, $top) {
			if ($i++ < $top) {
				self::join($client, $group['id']);
			}
		});
	}

	public static function pubAll($client) {

	}

	public static function run() {
		$client = new DoubanGroupClient("guluo98630786@163.com", "8hzeeo6ju5");
		$redis = self::getRedis();
		$status = $client->login();
		if ($status == false) {
			die;
		}

		self::searchJoin($client, "淘宝客兼职网赚");
//		self::searchJoin($client, "代购");
//		self::searchJoin($client, "网店");
//		self::searchJoin($client, "化妆品");
//		self::searchJoin($client, "女生");
//		self::searchJoin($client, "旅行");
		$groups = $client->myJoinGroup();
		foreach ($groups['groups'] as $g) {
			$redis->hSet("user:{$client->userId}:join", $g['id'], 1);
		}
		foreach ($groups['groups'] as $g) {
			self::pubUgg($client, $g['id']);
			self::pubHello($client, $g['id']);
		}

		self::pubHello($client, 316757);
		self::pubUgg($client, 316757);
	}
}

/**
 * yuexi02411832640@163.com----1ut3cld5e4
you2916331777@163.com----4jn2m66x3i
shoushi903443718@163.com----0t596xkjyw
aoxielangao26@163.com----1o4eksgrgj
guluo98630786@163.com----8hzeeo6ju5
menmijimen@163.com----3memiona9h
bibeishang981370@163.com----4yzt37p2wq
tuoyi8833806@163.com----2wipmqldw2
wafen357790@163.com----6qiui5dmki
lin4644656@163.com----6s1s93zgxl
 */
BusinessPlan::run();
