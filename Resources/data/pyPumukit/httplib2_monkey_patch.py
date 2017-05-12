#!/usr/bin/python

import httplib2

proxy_info_from_url = httplib2.proxy_info_from_url
def new_proxy_info_from_url(url, method='http'):
    pi = proxy_info_from_url(url, method)
    pi.proxy_type = 4
    return pi
httplib2.proxy_info_from_url = new_proxy_info_from_url
