<?php
date_default_timezone_set('PRC');
require_once dirname(__DIR__) . "/vendor/autoload.php";
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
define("DEFAULT_UA", "api-client/2.0 com.douban.group/3.3.9(339) Android/22 hammerhead LGE Nexus 5");
define("CLIENT_ID", "00a0951fbec80b0501e1bf5f3c58210f");
define("CLIENT_SECRET", "77faec137e9bda16");
define("LOG_NAME", dirname(__DIR__)."douban.log");
class DoubanGroupClient {

	public $username;
	
	public $password;

	public $userId;

	public $nickname;

	// 设备id
	public $udid;
	
	// 32位
	public $clientId;
 	
 	// 16位
 	public $clientSecret;

 	public $userAgent;

 	//登录以后的token
 	public $token;

	public static $logger;

	public $session;

	/**
	 * @var GuzzleHttp\Client
	 */
	public $apiClient;

	public function __construct($u, $p) {
		$this->username = $u;
		$this->password = $p;
		// 随机生成设备id
		$this->udid = md5(microtime()).substr(md5(microtime() + 1000), 0, 8);
		$this->clientId = CLIENT_ID;
		// 设置默认ua
		$this->userAgent = DEFAULT_UA;
		$this->clientSecret = CLIENT_SECRET;

		if (self::$logger == null) {
			self::$logger = new Logger(__CLASS__);
			self::$logger->pushHandler(new StreamHandler(LOG_NAME, Logger::INFO));
			self::$logger->pushHandler(new StreamHandler(STDOUT, Logger::INFO));
		}

		$this->session = new Redis();
		$this->session->connect("localhost");
	}

	private function saveLoginStatus($ttl) {
		$this->session->hSet("douban:{$this->username}:login", "userId", $this->userId);
		$this->session->hSet("douban:{$this->username}:login", "nickname", $this->nickname);
		$this->session->hSet("douban:{$this->username}:login", "username", $this->username);
		$this->session->hSet("douban:{$this->username}:login", "password", $this->password);
		$this->session->hSet("douban:{$this->username}:login", "token", $this->token);
		$this->session->expire("douban:{$this->username}:login",  $ttl);
	}

	private function setLoginStatus() {
		$rt = $this->session->hGetAll("douban:{$this->username}:login");
		$this->userId = @$rt['userId'];
		$this->nickname = @$rt['nickname'];
		$this->token = @$rt['token'];
	}

	public function login($force = false) {
		if (!$force) {
			// 读取登录信息
			$this->setLoginStatus();
			if (isset($this->token)) {
				// 设置登录后的api client
				$this->apiClient = new GuzzleHttp\Client([
					'base_uri' => 'https://api.douban.com',
					'timeout'  => 30.0,
					'headers'   => [
						'Authorization'=>"Bearer {$this->token}",
						'User-Agent'=>DEFAULT_UA,
					],
				]);
				self::$logger->info("记住登录成功|{$this->username} ($this->userId) 使用旧的token: {$this->token}");
				return true;
			}
		}
		try {
			$client = new GuzzleHttp\Client([
				'base_uri' => 'https://www.douban.com',
				'timeout'  => 30.0,
			]);
			$response = $client->request('POST', "/service/auth2/token?udid={$this->udid}", [
				'form_params' => [
					'password'=>$this->password,
					'grant_type'=>'password',
					'client_secret'=>$this->clientSecret,
					'redirect_uri'=>'http://group.douban.com/!service/android',
					'client_id'=>$this->clientId,
					'username'=>$this->username,
				]
			]);
			$body = $response->getBody();
			$result = json_decode($body->getContents(), true);
			if (isset($result['msg'])) {
				throw new Exception($result['msg']);
			}
			// 设置登录信息
			$this->userId = $result['douban_user_id'];
			$this->nickname = $result['douban_user_name'];
			$this->token = $result['access_token'];
			// 记住登录
			$this->saveLoginStatus($result['expires_in']);
			// 记录日志
			self::$logger->info("登录成功|{$this->username} ($this->userId) 生成token: {$this->token}");
			// 设置登录后的api client
			$this->apiClient = new GuzzleHttp\Client([
				'base_uri' => 'https://api.douban.com',
				'timeout'  => 30.0,
				'headers'   => [
					'Authorization'=>"Bearer {$this->token}",
					'User-Agent'=>DEFAULT_UA,
				],
			]);
		} catch (GuzzleHttp\Exception\RequestException $e) {
			self::$logger->warn("登录失败|".$e->getRequest()->getBody());
			if ($e->hasResponse()) {
				self::$logger->warn("登录失败|".$e->getResponse()->getBody());
			}
			return false;
		} catch (Exception $e) {
			self::$logger->warn("登录失败|".$e->getMessage());
			return false;
		}
		return true;
	}

	public function myJoinGroup($start = 0, $count = 50) {
		try {
			if ($this->apiClient == false) {
				throw new Exception("用户未登录");
			}
			$response = $this->apiClient->request('GET', "/v2/group/people/{$this->userId}/joined_groups", [
				'query' => [
					'apikey'=>$this->clientId,
					'start'=>$start,
					'count'=>$count,
					'udid'=>$this->udid,
				]
			]);

			$body = $response->getBody();
			$result = json_decode($body->getContents(), true);
			if (isset($result['msg'])) {
				throw new Exception($result['msg']);
			}
			$groups = [];
			uasort($result['groups'], function($a, $b) {
				if (intval($a['member_count']) == intval($b['member_count']))
					return 0;
				return (intval($a['member_count']) > intval($b['member_count'])) ? -1 : 1;
			});
			array_walk($result['groups'], function($group) use (&$groups) {
				$g['name'] = $group['name'];
				$g['member_count'] = $group['member_count'];
				$groups[] = $g;
			});
			self::$logger->info("获取{$this->username}加入的小组| 总结果:{$result['total']}个", $groups);
			return $result;
		} catch (GuzzleHttp\Exception\RequestException $e) {
			self::$logger->warn("获取{$this->username}加入的小组失败|".$e->getRequest()->getBody());
			if ($e->hasResponse()) {
				self::$logger->warn("获取{$this->username}加入的小组失败|".$e->getResponse()->getBody());
			}
			return false;
		} catch (Exception $e) {
			self::$logger->warn("获取{$this->username}加入的小组失败".$e->getMessage());
			return false;
		}
	}

	/**
	 * 搜索小组
	 */
	public function searchGroup($keywords, $start = 0, $count = 30) {
		try {
			$client = new GuzzleHttp\Client([
				'base_uri' => 'https://api.douban.com',
				'timeout'  => 30.0,
			]);
			$response = $client->request('GET', "/v2/group/group_search", [
				'query' => [
					'q'=>$keywords,
					'apikey'=>$this->clientId,
					'start'=>$start,
					'count'=>$count,
					'udid'=>$this->udid,
				]
			]);
			$body = $response->getBody();
			$result = json_decode($body->getContents(), true);
			if (isset($result['msg'])) {
				throw new Exception($result['msg']);
			}
			$groups = [];
			uasort($result['groups'], function($a, $b) {
				if (intval($a['member_count']) == intval($b['member_count']))
					return 0;
				return (intval($a['member_count']) > intval($b['member_count'])) ? -1 : 1;
			});
			array_walk($result['groups'], function($group) use (&$groups) {
				$g['name'] = $group['name'];
				$g['member_count'] = $group['member_count'];
				$groups[] = $g;
			});
			self::$logger->info("搜索群组 $keywords| 总结果:{$result['total']}个", $groups);
			return $result;
		} catch (GuzzleHttp\Exception\RequestException $e) {
			self::$logger->warn("搜索群组 $keywords 失败|".$e->getRequest()->getBody());
			if ($e->hasResponse()) {
				self::$logger->warn("搜索群组 $keywords 失败".$e->getResponse()->getBody());
			}
			return false;
		} catch (Exception $e) {
			self::$logger->warn("搜索群组 $keywords 失败".$e->getMessage());
			return false;
		}
	}

	/**
	 * 查询小组信息
	 * @param $gid
	 */
	public function groupInfo($gid) {
		try {
			$client = new GuzzleHttp\Client([
				'base_uri' => 'https://api.douban.com',
				'timeout'  => 30.0,
			]);
			$response = $client->request('GET', "/v2/group/{$gid}/", [
				'query' => [
					'apikey'=>$this->clientId,
					'udid'=>$this->udid,
				]
			]);
			$body = $response->getBody();
			$result = json_decode($body->getContents(), true);
			if (isset($result['msg'])) {
				throw new Exception($result['msg']);
			}
			self::$logger->info("查询小组 $gid| {$result['name']} 加入类型:{$result['join_type']}", $result);
			return $result;
		} catch (GuzzleHttp\Exception\RequestException $e) {
			self::$logger->warn("查询小组 $gid 失败|".$e->getRequest()->getBody());
			if ($e->hasResponse()) {
				self::$logger->warn("查询小组 $gid 失败".$e->getResponse()->getBody());
			}
			return false;
		} catch (Exception $e) {
			self::$logger->warn("查询小组 $gid 失败".$e->getMessage());
			return false;
		}
	}

	public function joinGroup($gid, $reason = "管理员大人求加一个，谢谢啦") {
		try {
			if ($this->apiClient == false) {
				throw new Exception("用户未登录");
			}
			$groupInfo = $this->groupInfo($gid);
			if ($groupInfo == false) {
				return false;
			}

			if ($groupInfo['join_type'] == "A") {
				// 自动加入
				$params = [
					'type'=>'join',
				];
			} else if ($groupInfo['join_type'] == "R") {
				// 申请加入
				$params = [
					'type'=>'request_join',
					'reason'=>$reason
				];
			} else {
				throw new Exception("不知道的群组加入类型 $gid {$groupInfo['join_type']}");
			}
			$response = $this->apiClient->request('POST', "/v2/group/{$gid}/join?udid={$this->udid}", [
				'form_params' => $params
			]);

			$body = $response->getBody();
			$result = json_decode($body->getContents(), true);
			if (isset($result['msg'])) {
				throw new Exception($result['msg']);
			}
			self::$logger->info("{$this->username} ({$this->userId}) 加入小组 $gid");
			return $result;
		} catch (GuzzleHttp\Exception\RequestException $e) {
			self::$logger->warn("加入小组 $gid 失败|".$e->getRequest()->getBody());
			if ($e->hasResponse()) {
				self::$logger->warn("加入小组 $gid 失败|".$e->getResponse()->getBody());
			}
			return false;
		} catch (Exception $e) {
			self::$logger->warn("加入小组 $gid 失败".$e->getMessage());
			return false;
		}
	}

	/**
	 * @param $content
	 * @param $pics array 图片文件地址
	 */
	public function publish($gid, $title, $content, $pics) {
		try {
			if ($this->apiClient == false) {
				throw new Exception("用户未登录");
			}
			$params['multipart'][] = [
				'name'=>'title',
				'contents'=>$title
			];
			if (count($pics) > 0) {
				foreach ($pics as $i=>$p) {
					$params['multipart'][] = [
						'name'     => 'file',
						'contents' => fopen($p, 'r'),
						'filename' => 'nofilename',
					];
					$pi = $i + 1;
					$content .= "\n<图片{$pi}>\n";
				}
			}
			$params['multipart'][] = [
				'name'=>'content',
				'contents'=>$content
			];
			$response = $this->apiClient->request('POST', "/v2/group/{$gid}/post?udid={$this->udid}", $params);
			$body = $response->getBody();
			$result = json_decode($body->getContents(), true);
			if (isset($result['msg'])) {
				throw new Exception($result['msg']);
			}
			self::$logger->info("{$this->username} ({$this->userId}) 帖子id: {$result['topic']['id']}");
			return $result;
		} catch (GuzzleHttp\Exception\RequestException $e) {
			if ($e->hasResponse()) {
				self::$logger->warn("发帖 $gid 失败|".$e->getResponse()->getBody());
			}
			return false;
		} catch (Exception $e) {
			self::$logger->warn("发帖 $gid 失败".$e->getMessage());
			return false;
		}
	}

	public function comment($postId, $content) {
		try {
			if ($this->apiClient == false) {
				throw new Exception("用户未登录");
			}
			$response = $this->apiClient->request('POST', "/v2/group/topic/{$postId}/add_comment?udid={$this->udid}", [
				'form_params' => [
					'content'=>$content
				]
			]);
			$body = $response->getBody();
			$result = json_decode($body->getContents(), true);
			if (isset($result['msg'])) {
				throw new Exception($result['msg']);
			}
			self::$logger->info("{$this->username} ({$this->userId}) 评论:$postId 内容:$content 评论id:{$result['id']}");
			return $result;
		} catch (GuzzleHttp\Exception\RequestException $e) {
			if ($e->hasResponse()) {
				self::$logger->warn("评论 $postId 失败|".$e->getResponse()->getBody());
			}
			return false;
		} catch (Exception $e) {
			self::$logger->warn("评论 $postId 失败".$e->getMessage());
			return false;
		}
	}


}

//$client = new DoubanGroupClient("doubanrobot1@163.com", "mengjun1990");
//$status = $client->login();
//if ($status == false) {
//	die;
//}
//$groups = $client->searchGroup("淘宝");
//$client->groupInfo($groups['groups'][0]['id']);
//$client->joinGroup($groups['groups'][0]['id']);
//$client->myJoinGroup(0, 50);
$content = <<<EOF
国内UGG代工厂
真正的皮毛一体
附带小票，各种配件套装
很齐全的UGG系列
价格公道
支持批发
加威：Queens_1125
EOF;

//$result = $client->publish(154288, "代工厂UGG原单，价格公道，加威Queens_1125", $content, [
//	__DIR__.DIRECTORY_SEPARATOR."douban.jpg",
//	__DIR__.DIRECTORY_SEPARATOR."ugg/douban1.jpg",
//	__DIR__.DIRECTORY_SEPARATOR."ugg/douban2.jpg",
//	__DIR__.DIRECTORY_SEPARATOR."ugg/douban3.jpg",
//	__DIR__.DIRECTORY_SEPARATOR."ugg/douban4.jpg",
//	__DIR__.DIRECTORY_SEPARATOR."ugg/douban5.jpg",
//	__DIR__.DIRECTORY_SEPARATOR."ugg/douban6.jpg",
//	__DIR__.DIRECTORY_SEPARATOR."ugg/douban7.jpg",
//	__DIR__.DIRECTORY_SEPARATOR."ugg/douban8.jpg",
//]);
//$postId = $result['topic']['id'];
//$postId = 79651112;
//$result = $client->comment($postId, "upupup");
//$commentId = $result['id'];