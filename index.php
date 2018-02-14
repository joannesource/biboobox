<?php
/**
 *   ~  BibouBox UI v1.2 by Joanne Source @GitHub  ~  A small standalone webUI for your pinguin uWu  ~ 
 * 
 * https://github.com/joannesource/biboobox
 * 
 */
//@todo purge of docker images must be done via a specific menu

$debug = 0;

# Helper functions.
if (php_sapi_name() == "cli") {
    array_shift($argv);
    $c = implode(' ', $argv);
    error_log($c);
    `$c`;
    die;
}

function getip() {
    global $CIP;
    if ($CIP)
        return $CIP;$IF = `ip r|grep "default via"|head -n1| awk '{ print $5 }' |tr -d '\n'`;
    return $CIP = str_replace("\n", '', `ip a | grep "$IF" | grep -oP '\d{1,3}(.\d{1,3}){3}' | head -1`);
}

function nempty($var) {
    return !empty($_GET[$var]);
}

function isempty($var) {
    return empty($_GET[$var]);
}

function v($var) {
    return isset($_GET[$var]) ? strval($_GET[$var]) : '';
}

function vd($a, $t = 0) {
    if ($t)
        echo " $t : ";var_dump($a);
    echo '<br/>';
}

function pbg($x) {
    pclose(popen($x, 'r'));
}

define('ROOT_CLI', 'sudo /usr/bin/php /var/www/html/index.php ');
define('PI_CLI', 'sudo -u pi /usr/bin/php /var/www/html/index.php ');


$IP = getip();
define('IP', $IP);
define('VERSION', '1.2');

/**     | Query     .- UI <-- [ Template ]
 * Map: |    `Menu -'
 *      |      \
 *      |    Kernel -- AppManager-<>--App
 *      |      `db
 */
class Kernel {

    const SYSTEM_SHUTDOWN = 'System.shutdown';
    const SYSTEM_RESTART = 'System.reboot';
    const SYSTEM_UPDATEX = 'System.update';
    const SYSTEM_RESTARTX = 'System.restargui';
    const SYSTEM_CLEARCACHE = 'System.clearcache';
    const INSTALLABLE = 'installable';
    const INSTALLED = 'installed';
    const LANG_EN = 'langEn';
    const LANG_FR = 'langFr';

    public $appManager;
    public $actions = [
        self::SYSTEM_SHUTDOWN   => [
            ROOT_CLI . 'shutdown -h now',
        ],
        self::SYSTEM_RESTART    => [
            ROOT_CLI . 'reboot',
        ],
        self::SYSTEM_UPDATEX    => [
            'git checkout /var/www/html/ && git -C /var/www/html pull origin master',
        ],
        self::SYSTEM_RESTARTX   => [
            PI_CLI . 'killall midori',
            PI_CLI . 'DISPLAY=:0 /usr/bin/midori -e Fullscreen -a http://localhost/',
        ],
        self::SYSTEM_CLEARCACHE => [
            ROOT_CLI . 'rm -f /var/www/html/db',
        ],
    ];
    public $db;

    function __construct() {
        $this->appManager = new AppManager;
        $this->db = new Database;
        $this->checkPendingApps();
    }

    function handle($action) {
        $actions = [
            self::LANG_EN,
            self::LANG_FR,
        ];
        if (!in_array($action, $actions))
            return;

        switch ($action) {
            case self::LANG_EN:
                $this->db->get('lang', 'lang', 'en');
                break;
            case self::LANG_FR:
                $this->db->get('lang', 'lang', 'fr');
                break;
        }
    }

    function getLang() {
        if (!$this->db->get('lang', 'lang'))
            $this->db->get('lang', 'lang', 'en');

        return $this->db->get('lang', 'lang');
    }

    function isValidAction($name) {
        return isset($this->actions[$name]);
    }

    function getAction($key) {
        if (isset($this->actions[$key]))
            return $this->actions[$key];
    }

    function execute($name) {
        if (!$this->isValidAction($name))
            throw new Exception('Biboobox Kernel exception : cannot execute unknown');
        $action = $this->getAction($name);

        // del cache

        $commands = is_array($action) ? $action : [$action];

        if ($commands) {
            $payload = '(' . implode(';', $commands) . ')';
            error_log($payload . ' 1>exec.log 2>&1 &');
            if (is_string($payload)) {
                pbg($payload . ' 1>exec.log 2>&1 &');
            }
        }
    }

    function getDynamicConf($filter) {
        if (!in_array($filter, [self::INSTALLABLE, self::INSTALLED]))
            throw new Exception('Biboobox Kernel exception : unknown dynamic conf filter');

        if (!$this->db->get('states'))
            $this->queryAllStates();

        $filtered = [];
        if ($filter === self::INSTALLED) {
            foreach ($this->db->query('states', -1, true) as $appName) {
                $filtered[] = $this->appManager->getApp($appName);
            }
        } elseif ($filter === self::INSTALLABLE) {
            foreach ($this->db->query('states', -1) as $appName) {
                $filtered[] = $this->appManager->getApp($appName);
            }
        }
        return $filtered;
    }

    /**
     * @return App
     */
    public function getAppByName($name) {
        return $this->appManager->getApp($name);
    }

    private function commandExecute($command) {
        $result = `$command`;
        $result = str_replace("\n", '', $result);
        if ($result === 'true')
            $result = 1;
        if ($result === 'false')
            $result = 0;
        return $result;
    }

    private function queryAllStates() {
        foreach ($this->appManager->getApps() as $app) {
            $this->queryState($app);
        }
    }

    private function statusOutputToInt($output) {
        if (!is_numeric($output)) {
            if ($output === 'true') {
                return 1;
            } elseif ($output === 'false') {
                return 0;
            }
        }
        return $output;
    }

    public function queryState(App $app) {
        $command = $app->getStatusBoolCommand();
        $status = $this->commandExecute($command);
        $status = $this->statusOutputToInt($status);
        $app->setState($status);
        if (($pending = $this->db->get('pending', $app->getName()))) {
            if (((int) $pending === App::STARTING && (int) $status === 1) ||
                    ((int) $pending === App::STOPPING && (int) $status == 0) ||
                    ((int) $pending === App::INSTALLING && ((int) $status === 0 || (int) $status === 1)) ||
                    ((int) $pending === App::UNINSTALLING && (int) $status === -1)) {
                $this->db->del('pending', $app->getName());
            }
        }
        $this->db->get('states', $app->getName(), $status);
    }

    public function getAppStates() {
        $states = [];
        foreach ($this->db->get('states') as $appName => $appHardState) {
            if (($softState = $this->db->get('pending', $appName)))
                $states[$appName] = $softState;
            else
                $states[$appName] = $appHardState;
        }
        return $states;
    }

    public function executeApp($appName, $actionKey) {
        if (!($app = $this->getAppByName($appName)))
            return;

        $pending = '';
        if ($actionKey == 'install')
            $pending = App::INSTALLING;
        elseif ($actionKey == 'remove')
            $pending = App::UNINSTALLING;
        elseif ($actionKey == 'stop')
            $pending = App::STOPPING;
        elseif ($actionKey == 'start')
            $pending = App::STARTING;

        $this->db->get('pending', $appName, $pending);

        $action = $app->conf[$actionKey];

        $commands = is_array($action) ? $action : [$action];

        if ($commands) {
            error_log(__FUNCTION__);
            $payload = '(' . implode(';', $commands) . ')';
            error_log($payload . ' 1>exec.log 2>&1 &');
            if (is_string($payload)) {
                pbg($payload . ' 1>exec.log 2>&1 &');
            }
        }
    }

    private function checkPendingApps() {
        foreach ($pending = $this->db->get('pending') as $appName => $pending) {
            // Resolving status.
            $this->queryState($this->getAppByName($appName));
        }
    }

}

class AppManager {

    private $conf = [
        'Kodi'       => [
            'install'     => ROOT_CLI . ' sudo apt -y install kodi',
            'remove'      => [ROOT_CLI . ' apt -y remove kodi kodi-bin', ROOT_CLI . ' apt-get -y autoremove'],
            'start'       => PI_CLI . ' /usr/bin/kodi --no-lirc &',
            'stop'        => 'kodi-send --action="Quit"',
            'status bool' => 'bash -c \'[[ `dpkg -s kodi 2>/dev/null` ]] && [[ `dpkg -s kodi|grep Status|grep deinstall|wc -l` -eq 0 ]] && ([[ `ps -ef|grep -v grep|grep kodi` ]] && echo 1 || echo 0) || echo -1\'',
            'info'        => 'Mediacenter',
        ],
        'Syncthing'  => [
            'install'     => [
                ROOT_CLI . '"curl -s https://syncthing.net/release-key.txt | sudo apt-key add -"',
                ROOT_CLI . '"echo "deb https://apt.syncthing.net/ syncthing stable" | sudo tee /etc/apt/sources.list.d/syncthing.list"',
                ROOT_CLI . 'sudo apt update',
                ROOT_CLI . 'sudo apt -y install syncthing',
            ],
            'remove'      => [ROOT_CLI . ' apt -y remove syncthing'],
            'start'       => PI_CLI . ' /usr/bin/syncthing -no-browser -gui-address=' . IP . ':8384 -home="/home/pi/.config/syncthing"',
            'stop'        => ROOT_CLI . 'killall syncthing',
            'status bool' => 'bash -c \'[[ `dpkg -s syncthing 2>/dev/null` ]] && [[ `dpkg -s syncthing|grep Status|grep deinstall|wc -l` -eq 0 ]] && ([[ `ps -ef|grep -v grep|grep syncthing` ]] && echo 1 || echo 0) || echo -1\'',
            'link'        => 'http://' . IP . ':8384',
            'info'        => 'Sync your files',
        ],
        'NextCloud'  => [
            'install'     => [
                'docker pull ownyourbits/nextcloudpi',
                'docker run -d -p 443:443 -p 81:80 -v ncdata:/data --name nextcloudpi ownyourbits/nextcloudpi ' . IP,
            ],
            'remove'      => 'docker stop nextcloudpi && docker rm nextcloudpi && docker volume rm ncdata', //&& docker rmi ownyourbits/nextcloudpi', 
            'start'       => [
                'docker start nextcloudpi',
                'sleep 2 && docker exec -t nextcloudpi bash -c \'[[ $(grep ' . IP . ' /var/www/nextcloud/config/config.php|wc -l) -eq 0 ]] && echo -e "\$CONFIG[\'\\\'\'trusted_domains\'\\\'\'][]=\'\\\'\'' . IP . '\'\\\'\';\n" >> /var/www/nextcloud/config/config.php;\'',
            ],
            'stop'        => 'docker stop nextcloudpi',
            'status bool' => 'docker inspect nextcloudpi --format="{{.State.Running}}"  2>/dev/null || echo -n -1',
            'info'        => 'Own your cloud, own your data',
            'link'        => 'https://' . IP,
            'password'    => 'admin / ownyourbits',
        ],
        'Retroshare' => [
            'install'     => [
                'sudo apt -y install gcr gnome-keyring libgck-1-0 libgcr-3-common libgcr-base-3-1 libgcr-ui-3-1 libmicrohttpd10 libpam-gnome-keyring libqt5multimedia5 libqt5x11extras5 libqt5xml5 libsqlcipher0 libupnp6 p11-kit p11-kit-modules',
                'bash -c "cd /var/www/html; ls retroshare06_0.6.1-1.53e26983.jessie_armhf.deb || wget https://github.com/RetroShare/RetroShare/releases/download/0.6.1/retroshare06_0.6.1-1.53e26983.jessie_armhf.deb"',
                'sudo dpkg -i retroshare06_0.6.1-1.53e26983.jessie_armhf.deb > retroshare.log 2>&1',
            ],
            'remove'      => ['sudo dpkg -r retroshare06', ROOT_CLI . ' rm -rf /home/pi/.retroshare/'],
            'start'       => PI_CLI . ' /usr/bin/RetroShare06-nogui --webinterface 1086 --http-allow-all &',
            'stop'        => ROOT_CLI . ' killall RetroShare06-nogui &',
            'status bool' => 'bash -c \'[[ `dpkg -s retroshare06 2>/dev/null` ]] && ([[ `ps -ef|grep -v grep|grep RetroShare` ]] && echo 1 || echo 0) || echo -1\'',
            'info'        => 'Decentralized messenger and private social network',
            'link'        => 'http://' . IP . ':1086',
        ],
        'Tor'        => [
            'install'     => [
                'sudo gpg --keyserver keys.gnupg.net --recv 886DDD89;sudo gpg --export A3C4F0F979CAA22CDBA8F512EE8CBC9E886DDD89 | sudo apt-key add --',
                'if ! grep -q -e "torproject.org" /etc/apt/sources.list; then echo "\ndeb http://deb.torproject.org/torproject.org jessie main\n"|sudo tee --append /etc/apt/sources.list 2>/dev/null; fi',
                'sudo apt update && sudo apt install -y tor',
                 ROOT_CLI.'\'if [ -e /etc/tor/torrc ]; then if [ ! $(grep -q -e "' . IP . '" /etc/tor/torrc) ]; then echo "\nSOCKSPort ' . IP . ':9100\n" | sudo tee --append /etc/tor/torrc; else echo nope; fi; fi;\'',
            ],
            'remove'      => [ROOT_CLI . 'sudo apt remove -y --purge tor', 'sudo sed -i "/torproject/d" /etc/apt/sources.list', 'sudo apt-key del 886DDD89'],
            'start'       => [
                ROOT_CLI . 'service tor start',
                ROOT_CLI . 'bash -c \'if [ -e /etc/tor/torrc ]; then if ! grep -q -e "' . IP . '" /etc/tor/torrc ; then echo "\nSOCKSPort ' . IP . ':9100\n" | sudo tee --append /etc/tor/torrc; else echo nope; fi; fi\'',
            ],
            'stop'        => 'sudo service tor stop',
            'status bool' => 'bash -c \'[[ -e /usr/sbin/tor ]] && ([[ `ps -ef|grep -v grep|grep /usr/bin/tor` ]] && echo 1 || echo 0) || echo -1\'',
            'info'        => 'Tor network. Socks5 proxy on port 9100',
        ],
        'TTRSS'      => [
            'install'     => [
                'docker pull joannesource/docker-tt-rss-arm7',
                'docker pull joannesource/docker-postgre-arm7',
                'docker run -d --name ttrssdb joannesource/docker-postgre-arm7:latest',
                'docker run -d --name ttrssweb --link ttrssdb:db -p 8081:80 joannesource/docker-tt-rss-arm7:latest',
            ],
            'remove'      => [
                'docker stop ttrssdb',
                'docker stop ttrssweb',
                'docker rm ttrssdb',
                'docker rm ttrssweb',
#'docker rmi joannesource/docker-postgre-arm7:latest',
#'docker rmi joannesource/docker-tt-rss-arm7:latest',
            ],
            'start'       => [
                'docker start ttrssdb',
                'docker start ttrssweb',
            ],
            'stop'        => [
                'docker stop ttssdb',
                'docker stop ttrssweb',
            ],
            'status bool' =>
            'docker inspect ttrssdb --format="{{.State.Running}}"  2>/dev/null || echo -n -1',
//                'docker inspect ttrssweb --format="{{.State.Running}}"  2>/dev/null || echo -n -1',
            //],
            'info'        => 'RSS Reader',
            'source'      => 'joannesource/docker-tt-rss-arm7',
            'link'        => 'http://' . IP . ':8081',
            'password'    => 'admin / password',
        ]
    ];
    private $apps;

    function __construct() {
        foreach ($this->getConf() as $name => $appConf) {
            $this->apps[$name] = new App($name, $appConf);
        }
    }

    public function getConf() {
        return $this->conf;
    }

    function getApps() {
        return $this->apps;
    }

    /**
     * @param string $name
     * @return App
     */
    function getApp($name) {
        return $this->apps[$name];
    }

    function appExists($name) {
        return isset($this->apps[$name]);
    }

}

class App {

    public $conf;
    private $name;
    private $state;

    const UNINSTALLED = -1;
    const INSTALLED = 0;
    const RUNNING = 1;
    const INSTALLING = 2;
    const STARTING = 3;
    const STOPPING = 4;
    const UNINSTALLING = 5;

    function __construct($name, $conf) {
        $this->conf = $conf;
        $this->name = $name;
    }

    function getName() {
        return $this->name;
    }

    function getState() {
        return $this->state;
    }

    function setState($state) {
        $this->state = $state;
    }

    function getStatusBoolCommand() {
        return $this->conf['status bool'];
    }

    function getAvailableConf() {
        if ($this->getState() == self::UNINSTALLED)
            return array_intersect_key($this->conf, array_flip(['install']));
        if ($this->getState() == self::INSTALLED)
            return array_diff_key($this->conf, array_flip(['install', 'remove', 'status bool', 'first start', 'stop',]));
        if ($this->getState() == self::RUNNING)
            return array_diff_key($this->conf, array_flip(['install', 'remove', 'status bool', 'first start', 'start',]));
    }

}

class Database {

    public $db;

    function __construct() {
        $this->db = file_exists($f = 'db') ? json_decode(file_get_contents($f), 1) : [];
    }

    function get($table, $key = null, $val = null) {
        if (is_null($key)) {
            return isset($this->db[$table]) ? $this->db[$table] : [];
        }
        if (!is_null($val)) {
            $return_val = null;
            if ($val === 'DEL')
                unset($this->db[$table][$key]);
            else
                $return_val = $this->db[$table][$key] = $val;
            $this->save();
            return $return_val;
        }
        if (isset($this->db[$table][$key]))
            return $this->db[$table][$key];
        return null;
    }

    function del($table, $key = null) {
        if (isset($this->db[$table][$key])) {
            unset($this->db[$table][$key]);
        }
    }

    function query($table, $searchValue, $revert = false) {
        $results = [];
        foreach ($this->db[$table] as $key => $row) {
            if ($revert) {
                if ($row != $searchValue)
                    $results[] = $key;
            } elseif ($row == $searchValue)
                $results[] = $key;
        }
        return $results;
    }

    function save() {
        file_put_contents('db', json_encode($this->db));
    }

    function statusDel($key) {
        $this->get($t = 'states', $key, 'DEL');
    }

}

class Menu {

    public $conf = [
        'Box'   => [
            'info' => 'Control your Biboobox',
            'wifi' => [
                'title'    => 'Wifi',
                'linkonly' => 'http://' . IP . '/wifi',
                'text'     => 'admin / secret',
            ], [
                'title'  => 'Turn off',
                'action' => Kernel::SYSTEM_SHUTDOWN,
            ], [
                'title'  => 'Restart',
                'action' => Kernel::SYSTEM_RESTART,
            ], [
                'title'  => 'Update',
                'action' => Kernel::SYSTEM_UPDATEX,
            ], [
                'title'  => 'Restart GUI',
                'action' => Kernel::SYSTEM_RESTARTX,
            ], [
                'title'  => 'Clear cache',
                'action' => Kernel::SYSTEM_CLEARCACHE,
            ],
        ],
        'Admin' => [
            'info'     => 'Administrate your services',
            'Install'  => [
                'title' => 'Install',
                'dyn'   => Kernel::INSTALLABLE,
            ],
            'Remove'   => [
                'title' => 'Uninstall',
                'dyn'   => Kernel::INSTALLED,
            ],
            'Language' => [
                [
                    'title'  => 'English',
                    'action' => Kernel::LANG_EN,
                ], [
                    'title'  => 'French',
                    'action' => Kernel::LANG_FR,
                ],
            ],
        ],
        'Apps'  => [
            'dyn' => Kernel::INSTALLED,
        ]
    ];
    public $queryGetValues;
    public $currentUri;
    private $kernel;
    private $currentDepth;
    private $parentMenuPath;
    private $currentPathMenu;
    private $currentPath;
    private $currentClearPath;
    private $currentMenuTitle;
    private $currentInfo;
    private $actionToConfirm;
    private $actionConfirmed;
    private $actionProcessing;
    private $dynamicMenu;
    private $translate;

    const K_PATH = 'p';

    function __construct() {
        $this->kernel = new Kernel;
        $this->queryGetValues = $_GET;
    }

    function getLang() {
        return $this->kernel->getLang();
    }

    function getVal($key) {
        if (!empty($this->queryGetValues[$key]))
            return $this->queryGetValues[$key];
    }

    function setTranslate(Translate $translate) {
        $this->translate = $translate;
    }

    public function getCurrentDepth() {
        return $this->currentDepth;
    }

    private function setCurrentDepth($currentDepth) {
        $this->currentDepth = $currentDepth;
    }

    function getParentMenuPath() {
        return $this->parentMenuPath;
    }

    function setParentMenuPath($parentMenuPath) {
        $this->parentMenuPath = $parentMenuPath;
    }

    public function getCurrentPathMenu() {
        return $this->currentPathMenu;
    }

    private function setCurrentPathMenu($currentPathMenu) {
        $this->currentPathMenu = $currentPathMenu;
    }

    function getCurrentPath() {
        return $this->currentPath;
    }

    function setCurrentPath($currentPath) {
        $this->currentPath = $currentPath;
    }

    function getCurrentMenuTitle() {
        return $this->currentMenuTitle;
    }

    function setCurrentMenuTitle($currentMenuTitle) {
        $this->currentMenuTitle = $currentMenuTitle;
    }

    function getCurrentInfo() {
        return $this->currentInfo;
    }

    function setCurrentInfo($currentInfo) {
        $this->currentInfo = $currentInfo;
    }

    function getActionToConfirm() {
        return $this->actionToConfirm;
    }

    function setActionToConfirm($actionToConfirm) {
        $this->actionToConfirm = $actionToConfirm;
    }

    function getCurrentClearPath() {
        return $this->currentClearPath;
    }

    function setCurrentClearPath($currentClearPath) {
        $this->currentClearPath = $currentClearPath;
    }

    function isActionConfirmed() {
        return $this->actionConfirmed;
    }

    function setActionConfirmed($actionConfirmed) {
        $this->actionConfirmed = $actionConfirmed;
    }

    function isActionProcessing() {
        return $this->actionProcessing;
    }

    function setActionProcessing($actionProcessing) {
        $this->actionProcessing = $actionProcessing;
    }

    function isDynamicMenu() {
        return $this->dynamicMenu;
    }

    function setDynamicMenu($dynamicMenu) {
        $this->dynamicMenu = $dynamicMenu;
    }

    function parseQuery() {
        if (($path = $this->queryGetValues[self::K_PATH])) {
            $this->setCurrentPath($path);
            $this->setCurrentClearPath($path);
            $fragments = explode('/', $path);
            $fragments = array_values(array_filter($fragments));
            $this->setCurrentDepth(count($fragments));
            $parentPathFragments = $fragments;
            array_pop($parentPathFragments);
            $this->setParentMenuPath(implode('/', $parentPathFragments));

            $depth = 0;
            if (count($fragments) > $depth) {
                if (!isset($this->conf[$fragments[$depth]])) {
                    throw new Exception('Unknown path');
                }
                $action = $this->conf[$fragments[$depth]];
                $this->setCurrentPathMenu($this->conf[$fragments[$depth]]);
                $this->setCurrentMenuTitle($fragments[$depth]);

                if (isset($this->conf[$fragments[$depth]]['info']))
                    $this->setCurrentInfo($this->conf[$fragments[$depth]]['info']);
                if (isset($action['dyn'])) {
                    $this->setDynamicMenu(1);
                    $this->setCurrentPathMenu($this->kernel->getDynamicConf($action['dyn']));
                }
            }

            $depth = 1;
            if (count($fragments) > $depth) {
                $frag = $fragments[$depth];
                if ($frag === 'action') {
                    $this->setCurrentClearPath($fragments[0]);
                    if (!isset($fragments[2]))
                        $fragments[2] = 0;
                    $action = $this->conf[$fragments[0]][$fragments[2]];
                    if ($this->kernel->isValidAction($action['action'])) {
                        $this->setActionToConfirm($action);
                        if ($this->getVal('confirm')) {
                            $this->setActionConfirmed(1);
                        }
                        if ($this->getVal('processing')) {
                            $this->setActionProcessing(1);
                            $this->kernel->execute($action['action']);
                            header('Location: /?p=' . $this->getCurrentClearPath());
                        }
                        return;
                    }
                    throw new Exception('Action not implemented');
                } else {
                    $this->setCurrentMenuTitle($fragments[$depth]);
                    if (isset($this->conf[$fragments[0]][$fragments[1]]) && ($action = $this->conf[$fragments[0]][$fragments[1]]) && isset($action['dyn'])) {
                        $this->setDynamicMenu($fragments[1]);
                        $this->setCurrentPathMenu($this->kernel->getDynamicConf($action['dyn']));
                    } elseif ($this->kernel->appManager->appExists($fragments[$depth])) {
                        $currentApp = $this->kernel->getAppByName($fragments[$depth]);
                        if (isset($currentApp->conf['info']))
                            $this->setCurrentInfo($currentApp->conf['info']);
                        $this->kernel->queryState($currentApp);
                        $this->setCurrentPathMenu($currentApp);
                    } else {
                        // List.
                        $this->setCurrentPathMenu($this->conf[$fragments[0]][$fragments[1]]);
                        $this->setCurrentMenuTitle($fragments[$depth]);
                    }
                }
            }

            $depth = 2;
            if (count($fragments) > $depth) {
                $frag = $fragments[$depth];
                if (in_array($this->isDynamicMenu(), ['Install', 'Remove']) && ($app = $this->kernel->getAppByName($frag))) {
                    $this->setCurrentClearPath($fragments[0] . '/' . $fragments[1]);
                    $actionName = strtolower($this->isDynamicMenu());
                    $this->setActionToConfirm($this->translate->translate($actionName) . ' : ' . $app->getName());

                    if ($this->getVal('confirm')) {
                        $this->setActionConfirmed(1);
                    }
                    if ($this->getVal('processing')) {
                        $this->setActionProcessing(1);
                        $this->kernel->executeApp($app->getName(), $actionName);
                        header('Location: /?p=' . $this->getCurrentClearPath());
                    }
                } elseif ($frag === 'action') {
                    $this->setCurrentClearPath($fragments[0] . '/' . $fragments[1]);
                    if (!isset($fragments[$depth + 1]))
                        $fragments[$depth + 1] = 0;
                    $actionName = $fragments[$depth + 1];
                    // @var $app App
                    $app = $this->getCurrentPathMenu();
                    if (is_array($app)) {
                        $this->kernel->handle($app[$actionName]['action']);
                        header('Location: /?p=' . $this->getCurrentClearPath());
                        return;
                    }
                    $this->setActionToConfirm($this->translate->translate($actionName) . ' : ' . $app->getName());
                    if ($this->getVal('confirm')) {
                        $this->setActionConfirmed(1);
                    }
                    if ($this->getVal('processing')) {
                        $this->setActionProcessing(1);
                        $this->kernel->executeApp($app->getName(), $actionName);
                        header('Location: /?p=' . $this->getCurrentClearPath());
                    }
                } else {
                    $currentApp = $this->kernel->getAppByName($fragments[$depth]);
                    $this->kernel->queryState($currentApp);
                    $this->setCurrentPathMenu($currentApp);
                }
            }
        } else {
            $this->setCurrentDepth(0);
            $this->setCurrentPathMenu($this->conf);
        }
    }

    function getMenu() {
        $conf = $this->getCurrentPathMenu();
        if ($conf instanceof App)
            $conf = $conf->getAvailableConf();

        if ($this->getCurrentMenuTitle() === 'Apps' && !$conf) {
            $conf[] = [
                'title'    => 'Install an app first',
                'linkonly' => '?p=/Admin/Install',
                'midori'   => true,
            ];
        }

        $menu = [];
        foreach ($conf as $key => $item) {
            if ($item instanceof App) {
                $appInstance = $item;
                $item = $appInstance->getAvailableConf();
                $key = $appInstance->getName();
            }
            if ($key === 'info')
                continue;
            $title = '?';
            $classes = [];
            $path = '';
            $text = '';
            $attr = '';
            if (!empty($item['text'])) {
                $text = $item['text'];
            }
            if (isset($item['title'])) {
                $title = $item['title'];
                if (!empty($item['linkonly'])) {
                    $path = '?p=' . $this->getCurrentPath();
                    if (!empty($item['midori']) || !$this->isBrowserMidori())
                        $path = $item['linkonly'];
                    $classes[] = 'status';
                }
                if (!empty($item['action'])) {
                    $path = '?p=' . $this->getCurrentPath() . '/action/' . $key;
                }
            } else {
                $title = $key;
            }

            if (!empty($key) && in_array($key, ['start', 'stop'])) {
                $path = '?p=' . $this->getCurrentPath() . '/action/' . $key;
            }
            if ($this->isDynamicMenu() === 'Install') {
                $path = '?p=' . $this->getCurrentPath() . "/$key/action/install";
            }
            if ($this->isDynamicMenu() === 'Remove') {
                $path = '?p=' . $this->getCurrentPath() . "/$key/action/remove";
            }

            if (!empty($key) && in_array($key, ['source', 'link', 'text', 'password'])) {
                $classes[] = 'status';
                $path = '?p=' . $this->getCurrentPath();
                if ($key === 'link' && !$this->isBrowserMidori()) {
                    $path = $item;
                    $attr = ' target="_blank" ';
                }
                $text = $item;
            }
            if (empty($path))
                $path = '?p=' . $this->getCurrentPath() . '/' . $key;

            $classes = ' ' . implode(' ', $classes);
            $menu[] = array_values([
                'href'       => $path,
                'classes'    => $classes,
                'attributes' => $attr,
                'title'      => $this->translate->translate($title),
                'instaval'   => '',
                'text'       => $text,
            ]);
        }
        return $menu;
    }

    function isBrowserMidori() {
        return strstr($_SERVER['HTTP_USER_AGENT'], 'Midori') !== false;
    }

    function getAppStates() {
        return $this->kernel->getAppStates();
    }

}

class UI {

    private $menu;
    private $translate;

    function __construct() {
        $this->menu = new Menu();
        if ($this->menu->getLang() === 'fr') {
            $this->translate = new FrenchTranslate;
        } else {
            $this->translate = new EnglishTranslate;
        }
        $this->menu->setTranslate($this->translate);
        $this->menu->parseQuery();
    }

    function getDate() {
        return date('d/m/Y H:i:s');
    }

    function getTitle() {
        if (($title = $this->menu->getCurrentMenuTitle()))
            return $this->translate->translate($title);
        return 'BibooBox v' . VERSION;
    }

    function getInfo() {
        return $this->translate->translate($this->menu->getCurrentInfo());
    }

    function hasGoBackLink() {
        return $this->menu->getCurrentDepth() > 0;
    }

    function showOverlay() {
        return $this->menu->getActionToConfirm() || $this->menu->isActionProcessing();
    }

    function buildMenu() {
        return $this->menu->getMenu();
    }

    function isStartingBlockingProcess() {
        return $this->menu->isActionConfirmed() && !$this->menu->isActionProcessing();
    }

    function processOngoingUri() {
        return '/?p=' . $this->menu->getCurrentPath() . '&confirm=1&processing=1';
    }

    function getCurrentUri() {
        return '/?p=' . $this->menu->getCurrentClearPath();
    }

    function getConfirmUri() {
        return '/?p=' . $this->menu->getCurrentPath() . '&confirm=1';
    }

    function getConfirmTitle() {
        $action = $this->menu->getActionToConfirm();
        if (is_string($action)) {
            return $action;
        } else {
            return $this->translate->translate($action['title']);
        }
    }

    private function stateToPrintable($state) {
        switch ($state) {
            case App::INSTALLING:
                return 'installing';
                break;
            case App::UNINSTALLING:
                return 'uninstalling';
                break;
            case App::STARTING:
                return 'starting';
                break;
            case App::STOPPING:
                return 'stopping';
                break;
            case App::RUNNING:
                return 'running';
                break;
        }
    }

    function printStates() {
        foreach ($this->menu->getAppStates() as $appName => $state) {
            if ((int) $state === App::UNINSTALLED || (int) $state === App::INSTALLED)
                continue;
            yield [$appName, $this->stateToPrintable($state)];
        }
    }

    function getGoBackUri() {
        return '/?p=' . $this->menu->getParentMenuPath();
    }

    public function _($string) {
        return $this->translate->translate($string);
    }

}

interface Translate {

    public function translate($string);
}

class EnglishTranslate implements Translate {

    public function translate($string) {
        return $string;
    }

}

class FrenchTranslate implements Translate {

    private $map = [
        'please wait...'             => 'Veuillez patienter...',
        'restart'                    => 'Redémarrer',
        'update'                     => 'Mettre à jour',
        'restart GUI'                => 'Redémarrer l\'interface',
        'install'                    => 'Installer',
        'uninstall'                  => 'Désinstaller',
        'control your biboobox'      => 'Contrôlez votre Biboobox',
        'administrate your services' => 'Contrôlez vos services',
        'admin'                      => 'Gestion',
        'install an app first'       => 'Installez d\'abord une application',
        'turn off'                   => 'Eteindre',
        'start'                      => 'Lancer',
        'stop'                       => 'Arrêter',
        'language'                   => 'Langue',
        'cancel'                     => 'Annuler',
        'confirm'                    => 'Confirmer',
        'password'                   => 'Mot de passe',
    ];

    public function translate($string) {
        if (isset($this->map[$s = strtolower($string)]))
            return $this->map[$s];
        return $string;
    }

}

$UI = new UI();
?>
<html>
    <head>
        <style type="text/css">
            body{ background: #536fc0; color: white; font-family: sans-serif; cursor: crosshair; min-height: 300px;} *{cursor: crosshair;}
            .link{min-width: 120px; box-sizing: border-box; padding: 16px 5px 5px 5px; font-size: 21px; position: relative; display: inline-block; height: 60px; background: #cc1dd1; text-align: center; margin: 3px; margin-right: 0; text-decoration: none; color: white; overflow: hidden; user-select: none;vertical-align:bottom;}
            .link:active { background: darkblue; cursor: progress; } .link:focus{cursor: progress;}
            .go-back{ background-color: #485ab7; width: 20px; min-width: 80px;}
            .status{ background-color: #4051a8; width: auto; padding: 16px 10px 0 10px;} .immutable{ cursor: default !important;}
            .link:active.immutable { background-color: #cc1dd1; cursor: pointer;} .link:focus{cursor: progress;}
            .root-title{ color: black; background: white;}
            .instaval{ font-size: small; display: block; pointer-events: none;  
                       /*position: absolute; top:50px; left: 0px; width: 100%;*/
                       color: #cdcdea;font-weight: bold;}
            @-webkit-keyframes pulse {0% { background-color: #3dc51b; }100% { background-color: #485ab7;}}
            .pulsedbox { -webkit-animation-name: pulse;-webkit-animation-duration: 0.5s;-webkit-animation-timing-function: ease-in-out;}
            .tag{background: white; border-radius: 6px; padding: 0px 4px;}
            .tag.ip{color: #536fc0;}
            .tag.installing{background: #5bc0de;}
            .tag.uninstalling{background: #d9534f;}
            .tag.starting{background: #f0ad4e;}
            .tag.running{background: #5cb85c;}
            .tag.stopping{background: #292b2c;}
        </style>	
        <?php
        if ($UI->isStartingBlockingProcess()) {
            echo '<meta http-equiv="refresh" content="0; url=' . $UI->processOngoingUri() . '">';
        }
        ?>
    </head>
    <body>

        <h2 style="margin-bottom:5px;display: inline-block;">
            <?= $UI->getTitle() ?>
        </h2>
        <span style="margin-left: 20px;color:#98aff2;"><?= $UI->getInfo() ?></span>
        <div style="clear: both"></div>
        <div style="position: absolute; right: 0; top: 0;"><?= $UI->getDate() ?><br/><span style="font-family: terminal,consolas, serif;" class="tag ip"><?= IP ?></span></div>

        <?php
        foreach ($UI->printStates() as list($name, $state)) {
            echo "<span class='tag $state'>$name</span>";
        }
        ?>
        <div id="nav">
            <?php if ($UI->hasGoBackLink()): ?>
                <a class="link go-back" href="<?= $UI->getGoBackUri() ?>">&larr;</a>
            <?php endif ?>
            <?php foreach ($UI->buildMenu() as list($href, $classes, $attributes, $title, $instaval, $text)): ?>
                <a href="<?= $href ?>" class="link<?= $classes ?>" <?= $attributes ?>><?= $title ?><br/><span class="instaval"> <?= $text ?></span></a>
            <?php endforeach ?>
        </div>
        <?php if ($UI->showOverlay()): ?>
            <div style="position:absolute;left:0;top:0; width:100%;height:375px; background:#FFF; opacity:0.55"></div>
            <div style="width: 300px; background: white; height: 200px; position:absolute; top:50px; left:20%;border: 10px solid #EEE;">

                <?php if ($UI->isStartingBlockingProcess()): ?>
                    <h3 style="color: red; text-align: center;"><?= $UI->_('Please wait...') ?></h3>
                <?php else: ?>setT
                    <h3 style="color: red; text-align: center;"><?= $UI->getConfirmTitle() ?></h3>
                    <div style="width: 375px; margin: 0 auto;">
                        <a class="link status immutable root-title" href="<?= $UI->getCurrentUri() ?>"><?= $UI->_('Cancel') ?></a>
                        <a class="link status immutable root-title" href="<?= $UI->getConfirmUri() ?>"><?= $UI->_('Confirm') ?></a>
                    </div>
                <?php endif ?>
            </div>
        <?php endif ?>
    </body>
</html>

