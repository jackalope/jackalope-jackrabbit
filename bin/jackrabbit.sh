#!/bin/bash

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR

VERSION="${JACKRABBIT_VERSION:-2.8.0}"
JAR=jackrabbit-standalone-$VERSION.jar

# download jackrabbit jar from archive, as the dist only contains the latest
# stable versions
if [ ! -f "$DIR/$JAR" ]; then
    wget -nv http://archive.apache.org/dist/jackrabbit/$VERSION/$JAR
fi

java -jar $DIR/$JAR&
pid=$!
echo "started prodcess $pid"

echo "Waiting until Jackrabbit is ready on port 8080"
while [[ -z `curl -s 'http://localhost:8080' ` ]]
do
    echo -n "."
    sleep 2s
    count=$(ps | grep "$pid[^[]" | wc -l)
    if [[ $count -eq 0 ]]
    then
        echo "process $pid not found, waiting on it to determine exit status"
        if wait $pid; then
		echo "jackrabbit terminated with success status (this should not happen)"
        else
                echo "jackrabbit failed (returned $?)"
        fi
	exit 1
    fi
done

echo "Jackrabbit is up"
