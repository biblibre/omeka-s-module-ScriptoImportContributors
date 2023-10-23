MODULE := $(notdir $(CURDIR))
VERSION := $(shell php -r 'echo parse_ini_file("config/module.ini")["version"];')
ZIP := $(MODULE)-$(VERSION).zip

.PHONY: dist clean

dist: $(ZIP)

$(ZIP):
	git archive -o $@ --prefix=$(MODULE)/ v$(VERSION)

clean:
	rm -f $(ZIP)
