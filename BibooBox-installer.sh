#!/bin/bash

# Biboo webUI needs root privilege.
echo -e "\nwww-data  ALL=(ALL) NOPASSWD: /usr/bin/php, /usr/bin/kodi-standalone, /usr/bin/RetroShare06-nogui, /usr/bin/dpkg, /usr/sbin/service, /usr/bin/apt-key, /usr/bin/tee, /usr/bin/gpg, /bin/sed, /usr/bin/apt\n" >> /etc/sudoers

# Dependencies
apt install midori vim htop apache2 libapache2-mod-php5 evtest kodi-eventclients-xbmc-send git
curl -sSL https://get.docker.com | sh
usermod -aG docker pi
usermod -aG docker www-data

rm /var/www/html/index.html
git -C /var/www/html clone https://github.com/joannesource/biboobox.git .
sed -i 's/Options Indexes FollowSymLinks/Options -Indexes -FollowSymLinks/' /etc/apache2/apache2.conf
cat >> /etc/apache2/apache2.conf <<EOF
IndexIgnore .git
RedirectMatch 404 /\.git
EOF

## midori autostart in ~/.config/autostart/biboo.desktop
sudo -u pi mkdir /home/pi/.config/autostart
sudo -u pi cat > /home/pi/.config/autostart/biboo.desktop <<EOF
[Desktop Entry]
Type=Application
Icon=path/icon.png
Name=BibooGUI
Comment=Description
Categories=Applications
Exec=/home/pi/.config/BibooStart
StartupNotify=true
Terminal=false
EOF

BOOTX=/home/pi/.config/BibooStart
sudo -u pi touch $BOOTX
sudo -u pi chmod +x $BOOTX
sudo -u pi cat > $BOOTX <<EOF
#!/bin/bash
sudo setfacl -m u:pi:r--  /dev/input/event0
/usr/bin/midori -e Fullscreen -a http://localhost/
EOF

# Wifi ( access : admin / secret )
WifiDir=/var/www/wifi
WifiEtc=/etc/raspap
mkdir $WifiDir $WifiEtc
git -C $WifiDir clone https://github.com/joannesource/raspap-webgui.git .
mv $WifiDir/raspap.php $WifiEtc
chown -R www-data. $WifiDir $WifiEtc
cat > /etc/apache2/sites-available/wifi.conf <<EOF
NameVirtualHost *
Alias /wifi /var/www/wifi
<VirtualHost *>
        ServerAdmin admin@localhost

        DocumentRoot /var/www/wifi
        <Directory />
                Options FollowSymLinks
                AllowOverride None
        </Directory>
        <Directory /var/www/raspap-webgui>
                Options Indexes FollowSymLinks MultiViews
                AllowOverride none
                Order allow,deny
                allow from all
        </Directory>
</VirtualHost>
EOF
a2ensite wifi
service apache2 restart
cat >> /etc/sudoers <<EOF
www-data ALL=(ALL) NOPASSWD:/sbin/ifdown wlan0
www-data ALL=(ALL) NOPASSWD:/sbin/ifup wlan0
www-data ALL=(ALL) NOPASSWD:/bin/cat /etc/wpa_supplicant/wpa_supplicant.conf
www-data ALL=(ALL) NOPASSWD:/bin/cp /tmp/wifidata /etc/wpa_supplicant/wpa_supplicant.conf
www-data ALL=(ALL) NOPASSWD:/sbin/wpa_cli scan_results
www-data ALL=(ALL) NOPASSWD:/sbin/wpa_cli scan
www-data ALL=(ALL) NOPASSWD:/sbin/wpa_cli reconfigure
www-data ALL=(ALL) NOPASSWD:/bin/cp /tmp/hostapddata /etc/hostapd/hostapd.conf
www-data ALL=(ALL) NOPASSWD:/etc/init.d/hostapd start
www-data ALL=(ALL) NOPASSWD:/etc/init.d/hostapd stop
www-data ALL=(ALL) NOPASSWD:/etc/init.d/dnsmasq start
www-data ALL=(ALL) NOPASSWD:/etc/init.d/dnsmasq stop
www-data ALL=(ALL) NOPASSWD:/bin/cp /tmp/dhcpddata /etc/dnsmasq.conf
www-data ALL=(ALL) NOPASSWD:/sbin/shutdown -h now
www-data ALL=(ALL) NOPASSWD:/sbin/reboot
EOF
chown -R www-data. /var/www
cp biboobox.png /usr/share/plymouth/themes/pix/splash.png
sudo dpkg-reconfigure tzdata
echo Install finished
