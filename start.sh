#/bin/bash

pid=$(pidof skeleton.Master)

if [ $pid ]; then
    kill $pid
fi

php bin/hyperf.php start