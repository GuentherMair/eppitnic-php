#!/bin/bash
#
# please install HAVEGED first! (apt-get install haveged)
#
# references
#  https://www.digitalocean.com/community/tutorials/how-to-setup-dnssec-on-an-authoritative-bind-dns-server--2
#  https://www.digitalocean.com/community/tutorials/how-to-setup-additional-entropy-for-cloud-servers-using-haveged

if [ $# -lt 2 ]; then
  echo "SYNTAX: ${0} DOMAIN ZONEFILE"
  exit 1
fi

DOMAIN=${1}
ZONEFILE=`basename ${2}`

if [ ! -f ${ZONEFILE} ]; then
  echo "Zone file '${ZONEFILE}' not found in current folder!"
  exit 2
fi

dnssec-keygen -a RSASHA512 -b 2048 -n ZONE ${DOMAIN}
dnssec-keygen -f KSK -a RSASHA512 -b 4096 -n ZONE ${DOMAIN}
for key in `ls K${DOMAIN}*.key`; do
   echo "\$INCLUDE $key" >>${ZONEFILE}
done
dnssec-signzone -A -3 $(head -c 1000 /dev/random | sha1sum | cut -b 1-16) -N INCREMENT -o ${DOMAIN} -t ${ZONEFILE}

if [ ! -f ${ZONEFILE}.signed ]; then
  echo "Generation of signed zone file failed ('${ZONEFILE}' not found)!"
  exit 4
fi

if [ ! -f dsset-${ZONEFILE}. ]; then
  echo "Generation of DSSET file failed ('dsset-${ZONEFILE}.' not found)!"
  exit 8
fi

echo
cat dsset-${ZONEFILE}.
echo
